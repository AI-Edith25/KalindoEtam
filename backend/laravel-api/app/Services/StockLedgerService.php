<?php

namespace App\Services;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
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
        int $qtyChange,
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
    public function getCurrentBalance(string $itemId, string $warehouseId): int
    {
        return $this->stockLedgerRepository->latestBalance($itemId, $warehouseId);
    }

    /**
     * Same as getCurrentBalance() but unlocked — for display-only reads
     * (a draft document's "here's what the system currently says"
     * snapshot) that must never hold a row lock. Never use this to decide
     * what to actually write to the ledger; see recordToBalance().
     */
    public function peekBalance(string $itemId, string $warehouseId): int
    {
        return $this->stockLedgerRepository->latestBalanceUnlocked($itemId, $warehouseId);
    }

    /** One row per (item, warehouse) pair with any ledger history, filtered — the Inventory module's Stock Balance report. */
    public function currentBalances(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->stockLedgerRepository->currentBalances($filters, $perPage);
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
        int $targetBalance,
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

            if ($qtyChange === 0) {
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
