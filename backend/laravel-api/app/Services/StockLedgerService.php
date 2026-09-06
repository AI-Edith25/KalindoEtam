<?php

namespace App\Services;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Models\FifoLayer;
use App\Models\FifoLayerConsumption;
use App\Models\StockLedger;
use App\Repositories\ItemRepository;
use App\Repositories\StockLedgerRepository;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockLedgerService
{
    public function __construct(
        protected StockLedgerRepository $stockLedgerRepository,
        protected ItemRepository $itemRepository,
    ) {}

    /**
     * Single entry point for every inventory transaction. Writes the ledger
     * entry (source of truth) and refreshes Item.current_stock (cache only).
     */
    public function record(
        string $itemId,
        string $warehouseId,
        StockTransactionType $transactionType,
        StockVoucherType $voucherType,
        string $voucherId,
        int|float $qtyChange,
        DateTimeInterface $postingDatetime,
        ?string $referenceNo = null,
        ?string $remarks = null,
    ): StockLedger {
        return DB::transaction(function () use (
            $itemId,
            $warehouseId,
            $transactionType,
            $voucherType,
            $voucherId,
            $qtyChange,
            $postingDatetime,
            $referenceNo,
            $remarks,
        ) {
            $lastBalance = $this->stockLedgerRepository->latestBalance($itemId, $warehouseId);
            $balanceQty = $lastBalance + $qtyChange;

            $ledger = $this->stockLedgerRepository->create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'transaction_type' => $transactionType,
                'voucher_type' => $voucherType,
                'voucher_id' => $voucherId,
                'reference_no' => $referenceNo,
                'qty_change' => $qtyChange,
                'balance_qty' => $balanceQty,
                'posting_datetime' => $postingDatetime,
                'remarks' => $remarks,
            ]);

            $item = $this->itemRepository->findOrFail($itemId);
            $totalStock = $this->stockLedgerRepository->totalBalanceForItem($itemId);
            $this->itemRepository->updateCurrentStock($item, $totalStock);

            return $ledger;
        });
    }

    public function historyForItem(string $itemId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->stockLedgerRepository->historyForItem($itemId, $perPage);
    }

    /** All ledger entries across every item/warehouse, filtered — the Inventory module's Stock Ledger report. */
    public function listAll(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->stockLedgerRepository->search($filters, $perPage);
    }

    /**
     * Annotates each row of a Stock Ledger page with unit_cost/value_in/value_out/
     * balance_value — transient attributes (not real columns), read by StockLedgerResource.
     * unit_cost/value_in/value_out are exact: an IN row's cost comes from the one FifoLayer
     * that voucher created, an OUT row's from the exact FifoLayerConsumption audit trail for
     * that voucher — never guessed. balance_value is the one approximation: it prices the
     * row's running balance_qty at *today's* weighted-average cost for that item+warehouse,
     * not a full historical replay of what the average was on that row's own date (this
     * ledger only ever stored qty, never a per-row cost snapshot, so an exact historical
     * figure isn't reconstructable without redesigning the table).
     */
    public function attachCostInfo(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $rows = $paginator->getCollection();

        if ($rows->isEmpty()) {
            return $paginator;
        }

        $voucherIds = $rows->pluck('voucher_id')->unique()->values()->all();

        $layersByKey = FifoLayer::query()
            ->whereIn('source_id', $voucherIds)
            ->get()
            ->groupBy(fn (FifoLayer $layer) => "{$layer->source_type}|{$layer->source_id}|{$layer->item_id}|{$layer->warehouse_id}");

        $consumptionsByKey = FifoLayerConsumption::query()
            ->whereIn('consuming_source_id', $voucherIds)
            ->with('fifoLayer')
            ->get()
            ->groupBy(fn (FifoLayerConsumption $c) => "{$c->consuming_source_type}|{$c->consuming_source_id}|{$c->fifoLayer->item_id}|{$c->fifoLayer->warehouse_id}");

        $itemWarehousePairs = $rows->map(fn (StockLedger $row) => "{$row->item_id}|{$row->warehouse_id}")->unique();
        $currentAverageCosts = $this->currentAverageCostsFor($itemWarehousePairs);

        foreach ($rows as $row) {
            /** @var StockLedger $row */
            $qtyChange = (float) $row->qty_change;
            $key = "{$row->voucher_type->value}|{$row->voucher_id}|{$row->item_id}|{$row->warehouse_id}";

            $unitCost = 0.0;
            if ($qtyChange > 0 && $layersByKey->has($key)) {
                $unitCost = (float) $layersByKey[$key]->first()->unit_cost;
            } elseif ($qtyChange < 0 && $consumptionsByKey->has($key)) {
                $consumed = $consumptionsByKey[$key];
                $qty = (float) $consumed->sum('qty_consumed');
                $cost = (float) $consumed->sum(fn (FifoLayerConsumption $c) => (float) $c->qty_consumed * (float) $c->unit_cost);
                $unitCost = $qty > 0 ? $cost / $qty : 0.0;
            }

            $row->unit_cost = round($unitCost, 2);
            $row->value_in = $qtyChange > 0 ? round($qtyChange * $unitCost, 2) : 0.0;
            $row->value_out = $qtyChange < 0 ? round(abs($qtyChange) * $unitCost, 2) : 0.0;
            $row->balance_value = round((float) $row->balance_qty * ($currentAverageCosts["{$row->item_id}|{$row->warehouse_id}"] ?? 0.0), 2);
        }

        return $paginator;
    }

    /** @param  \Illuminate\Support\Collection<int, string>  $itemWarehousePairs  "item_id|warehouse_id" keys */
    private function currentAverageCostsFor($itemWarehousePairs): array
    {
        $itemIds = $itemWarehousePairs->map(fn ($pair) => explode('|', $pair)[0])->unique()->values()->all();
        $warehouseIds = $itemWarehousePairs->map(fn ($pair) => explode('|', $pair)[1])->unique()->values()->all();

        return FifoLayer::query()
            ->whereIn('item_id', $itemIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('qty_remaining', '>', 0)
            ->get()
            ->groupBy(fn (FifoLayer $layer) => "{$layer->item_id}|{$layer->warehouse_id}")
            ->map(function ($layers) {
                $qty = (float) $layers->sum(fn (FifoLayer $l) => (float) $l->qty_remaining);
                $value = (float) $layers->sum(fn (FifoLayer $l) => (float) $l->qty_remaining * (float) $l->unit_cost);

                return $qty > 0 ? $value / $qty : 0.0;
            })
            ->all();
    }

    /** Daily stock-in/out over a period — reused by DashboardService for the Inventory Movement chart (docs/DASHBOARD_DESIGN.md §5), not recomputed there. */
    public function movementByDateRange(string $dateFrom, string $dateTo): Collection
    {
        return $this->stockLedgerRepository->movementByDateRange($dateFrom, $dateTo);
    }

    /**
     * Current on-hand balance for an item at a warehouse. Routed through
     * this service (not the repository directly) so every stock read, not
     * just every stock write, has a single gateway.
     */
    public function getCurrentBalance(string $itemId, string $warehouseId): float
    {
        return $this->stockLedgerRepository->latestBalance($itemId, $warehouseId);
    }

    /**
     * Same as getCurrentBalance() but unlocked — for display-only reads
     * (a draft document's "here's what the system currently says"
     * snapshot) that must never hold a row lock. Never use this to decide
     * what to actually write to the ledger; see recordToBalance().
     */
    public function peekBalance(string $itemId, string $warehouseId): float
    {
        return $this->stockLedgerRepository->latestBalanceUnlocked($itemId, $warehouseId);
    }

    /** One row per (item, warehouse) pair with any ledger history, filtered — the Inventory module's Stock Balance report. */
    public function currentBalances(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->stockLedgerRepository->currentBalances($filters, $perPage);
    }

    /** Total inventory value + item count across every row the Stock Balance report's current filters match — always the full set, not just the current page. */
    public function totalValueSummary(array $filters): array
    {
        return $this->stockLedgerRepository->totalValueSummary($filters);
    }

    /**
     * Writes whatever ledger entry is needed so the item+warehouse's balance
     * becomes exactly $targetBalance — the concurrency-safe way to reconcile
     * a physical count. The read (locked) and the write happen in the same
     * transaction, so the result is guaranteed correct even if another
     * transaction is concurrently writing to the same item+warehouse
     * (unlike computing the delta from a separate, earlier read — see
     * StockAdjustmentService for why that was rejected). Returns null and
     * writes nothing when the count already matches (no ledger noise for a
     * line that didn't actually move).
     */
    public function recordToBalance(
        string $itemId,
        string $warehouseId,
        int|float $targetBalance,
        StockTransactionType $transactionType,
        StockVoucherType $voucherType,
        string $voucherId,
        DateTimeInterface $postingDatetime,
        ?string $referenceNo = null,
        ?string $remarks = null,
    ): ?StockLedger {
        return DB::transaction(function () use (
            $itemId,
            $warehouseId,
            $targetBalance,
            $transactionType,
            $voucherType,
            $voucherId,
            $postingDatetime,
            $referenceNo,
            $remarks,
        ) {
            $lastBalance = $this->stockLedgerRepository->latestBalance($itemId, $warehouseId);
            $qtyChange = $targetBalance - $lastBalance;

            // Epsilon, not === 0: $lastBalance round-trips through a decimal:4 cast,
            // so float arithmetic here can leave a residue like 0.00000000001.
            if (abs($qtyChange) < 0.00005) {
                return null;
            }

            $ledger = $this->stockLedgerRepository->create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'transaction_type' => $transactionType,
                'voucher_type' => $voucherType,
                'voucher_id' => $voucherId,
                'reference_no' => $referenceNo,
                'qty_change' => $qtyChange,
                'balance_qty' => $targetBalance,
                'posting_datetime' => $postingDatetime,
                'remarks' => $remarks,
            ]);

            $item = $this->itemRepository->findOrFail($itemId);
            $totalStock = $this->stockLedgerRepository->totalBalanceForItem($itemId);
            $this->itemRepository->updateCurrentStock($item, $totalStock);

            return $ledger;
        });
    }
}
