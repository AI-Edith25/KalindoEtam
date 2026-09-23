<?php

namespace App\Repositories;

use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockLedgerRepository extends BaseRepository
{
    public function __construct(StockLedger $model)
    {
        parent::__construct($model);
    }

    /**
     * SUM(qty_change), not the "latest by posting_datetime" row's balance_qty: rows aren't
     * posted in timestamp order (Opening Stock posts at its cutoff_date, cancellations and
     * Goods Receipts at now()), so a later-dated row would otherwise hide every movement
     * posted after it — same reasoning as currentBalances().
     */
    public function latestBalance(string $itemId, string $warehouseId): float
    {
        return (float) $this->model->query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->sum('qty_change');
    }

    public function historyForItem(string $itemId, int $perPage = 15)
    {
        return $this->withRunningBalance($this->model->query())
            ->where('item_id', $itemId)
            ->orderByDesc('posting_datetime')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Overrides the stored balance_qty with a running SUM(qty_change) in posting order
     * (posting_datetime, then created_at) per item+warehouse. The stored column was written
     * off "whatever row sorted latest at write time", so it's wrong for any row posted out of
     * timestamp order (e.g. a Goods Receipt behind a later-dated Opening Stock). Correlated
     * subquery rather than a window so the report's filters don't shrink the running total.
     */
    protected function withRunningBalance($query)
    {
        return $query->select('stock_ledgers.*')->selectRaw(
            '(SELECT SUM(prior.qty_change) FROM stock_ledgers prior
                WHERE prior.item_id = stock_ledgers.item_id
                  AND prior.warehouse_id = stock_ledgers.warehouse_id
                  AND prior.deleted_at IS NULL
                  AND (prior.posting_datetime < stock_ledgers.posting_datetime
                       OR (prior.posting_datetime = stock_ledgers.posting_datetime AND prior.created_at <= stock_ledgers.created_at))
            ) as balance_qty'
        );
    }

    /** All ledger entries across every item/warehouse — the report view. Same filtering shape as GoodsReceiptRepository::search(), applied to posting_datetime. */
    public function search(array $filters, int $perPage = 15)
    {
        return $this->withRunningBalance($this->model->query())
            ->with(['item', 'warehouse'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['item_id'] ?? null, fn ($query, $itemId) => $query->where('item_id', $itemId))
            ->when($filters['voucher_type'] ?? null, fn ($query, $voucherType) => $query->where('voucher_type', $voucherType))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('posting_datetime', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('posting_datetime', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('reference_no', 'like', "%{$search}%")
                    ->orWhereHas('item', fn ($sq) => $sq->where('item_code', 'like', "%{$search}%")
                        ->orWhere('item_name', 'like', "%{$search}%"))
            ))
            ->orderByDesc('posting_datetime')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /** Item.current_stock: the item's net on-hand qty across every warehouse. */
    public function totalBalanceForItem(string $itemId): float
    {
        return (float) $this->model->query()->where('item_id', $itemId)->sum('qty_change');
    }

    /** latestBalance() minus the row lock — display-only reads, never for deciding a ledger write. */
    public function latestBalanceUnlocked(string $itemId, string $warehouseId): float
    {
        return (float) $this->model->query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->sum('qty_change');
    }

    /**
     * Batched latestBalanceUnlocked() for many items in one warehouse (Sales Order's item
     * dropdown) — one grouped query instead of N+1. Display-only.
     *
     * @param  string[]  $itemIds
     * @return array<string, float> keyed by item_id — an item with no ledger rows yet
     *                              simply doesn't appear (caller treats a missing key as 0).
     */
    public function latestBalancesForItems(array $itemIds, string $warehouseId): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $this->model->query()
            ->selectRaw('item_id, SUM(qty_change) as qty')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id')
            ->pluck('qty', 'item_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    /**
     * Daily stock-in vs stock-out over a period — the Inventory Movement
     * chart's only data source (docs/DASHBOARD_DESIGN.md §5). qty_change is
     * already signed (positive = in, negative = out, per record()'s own
     * contract), so this is a plain grouped sum, not a new stock concept.
     */
    public function movementByDateRange(string $dateFrom, string $dateTo): Collection
    {
        return $this->model->query()
            ->whereBetween('posting_datetime', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->selectRaw('DATE(posting_datetime) as date')
            ->selectRaw('SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END) as stock_in')
            ->selectRaw('SUM(CASE WHEN qty_change < 0 THEN -qty_change ELSE 0 END) as stock_out')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * One row per (item, warehouse) pair that has any ledger history — "what
     * do we have, where." No balances table exists; current_qty is
     * SUM(qty_change) per pair — the same net-movement math the Valuation
     * report's Closing Qty uses — not any single row's stored balance_qty.
     * balance_qty is a running total computed at write time off "whatever
     * row currently sorts latest by posting_datetime"; that ordering can
     * disagree with true chronological/insertion order (e.g. a same-day
     * cancellation posts at now() while its document posts at a midnight
     * cutoff_date), so picking "the latest row" here could surface a
     * stale/reversed balance even though the net movement is correct.
     */
    public function currentBalances(array $filters, int $perPage = 15)
    {
        $qtyTotals = $this->model->query()
            ->select('item_id', 'warehouse_id')
            ->selectRaw('SUM(qty_change) as current_qty')
            ->groupBy('item_id', 'warehouse_id');

        // FifoLayer, not this table, is the cost source of truth — qty_remaining here should
        // already agree with current_qty above (same item+warehouse), but a LEFT JOIN keeps a
        // row with no layers (yet) from disappearing, rather than an inner join silently
        // dropping it from the report.
        $fifoValues = DB::table('fifo_layers')
            ->whereNull('deleted_at')
            ->select('item_id', 'warehouse_id')
            ->selectRaw('SUM(qty_remaining) as fifo_qty')
            ->selectRaw('SUM(qty_remaining * unit_cost) as fifo_value')
            ->groupBy('item_id', 'warehouse_id');

        return DB::query()
            ->fromSub($qtyTotals, 'qty_totals')
            ->join('items', 'items.id', '=', 'qty_totals.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'qty_totals.warehouse_id')
            ->join('uoms', 'uoms.id', '=', 'items.uom_id')
            ->leftJoinSub($fifoValues, 'fifo_values', function ($join) {
                $join->on('fifo_values.item_id', '=', 'qty_totals.item_id')
                    ->on('fifo_values.warehouse_id', '=', 'qty_totals.warehouse_id');
            })
            ->select([
                'qty_totals.item_id',
                'items.item_code',
                'items.item_name',
                'qty_totals.warehouse_id',
                'warehouses.name as warehouse_name',
                'qty_totals.current_qty',
                'uoms.name as uom',
                DB::raw('COALESCE(fifo_values.fifo_value, 0) as total_value'),
            ])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('qty_totals.warehouse_id', $warehouseId))
            ->when($filters['item_group_id'] ?? null, fn ($query, $itemGroupId) => $query->where('items.item_group_id', $itemGroupId))
            ->when($filters['item_id'] ?? null, fn ($query, $itemId) => $query->where('qty_totals.item_id', $itemId))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('items.item_code', 'like', "%{$search}%")
                    ->orWhere('items.item_name', 'like', "%{$search}%")
            ))
            ->orderBy('items.item_code')
            ->orderBy('warehouses.name')
            ->paginate($perPage);
    }

    /**
     * Total inventory value + distinct item count across every row matching the Stock Balance
     * report's filters — always the full filtered set, never just the current page, same
     * convention as FifoValuationService::summary(). Reuses currentBalances()'s own filtering,
     * just against an unpaginated get() instead.
     */
    public function totalValueSummary(array $filters): array
    {
        $qtyTotals = $this->model->query()
            ->select('item_id', 'warehouse_id')
            ->groupBy('item_id', 'warehouse_id');

        $fifoValues = DB::table('fifo_layers')
            ->whereNull('deleted_at')
            ->select('item_id', 'warehouse_id')
            ->selectRaw('SUM(qty_remaining * unit_cost) as fifo_value')
            ->groupBy('item_id', 'warehouse_id');

        $rows = DB::query()
            ->fromSub($qtyTotals, 'qty_totals')
            ->join('items', 'items.id', '=', 'qty_totals.item_id')
            ->leftJoinSub($fifoValues, 'fifo_values', function ($join) {
                $join->on('fifo_values.item_id', '=', 'qty_totals.item_id')
                    ->on('fifo_values.warehouse_id', '=', 'qty_totals.warehouse_id');
            })
            ->select([
                'qty_totals.item_id',
                DB::raw('COALESCE(fifo_values.fifo_value, 0) as total_value'),
            ])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('qty_totals.warehouse_id', $warehouseId))
            ->when($filters['item_group_id'] ?? null, fn ($query, $itemGroupId) => $query->where('items.item_group_id', $itemGroupId))
            ->when($filters['item_id'] ?? null, fn ($query, $itemId) => $query->where('qty_totals.item_id', $itemId))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('items.item_code', 'like', "%{$search}%")
                    ->orWhere('items.item_name', 'like', "%{$search}%")
            ))
            ->get();

        return [
            'total_value' => round((float) $rows->sum('total_value'), 2),
            'item_count' => $rows->pluck('item_id')->unique()->count(),
        ];
    }
}
