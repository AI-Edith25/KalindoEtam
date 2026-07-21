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

    public function latestBalance(string $itemId, string $warehouseId): int
    {
        return (int) $this->model->query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('posting_datetime')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->value('balance_qty') ?? 0;
    }

    public function historyForItem(string $itemId, int $perPage = 15)
    {
        return $this->model->query()
            ->where('item_id', $itemId)
            ->orderByDesc('posting_datetime')
            ->paginate($perPage);
    }

    /** All ledger entries across every item/warehouse — the report view. Same filtering shape as GoodsReceiptRepository::search(), applied to posting_datetime. */
    public function search(array $filters, int $perPage = 15)
    {
        return $this->model->query()
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
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Item.current_stock represents the item's total on-hand quantity
     * across every warehouse, not just the one most recently touched —
     * sum each warehouse's latest balance, not just the latest write.
     */
    public function totalBalanceForItem(string $itemId): int
    {
        $warehouseIds = $this->model->query()
            ->where('item_id', $itemId)
            ->distinct()
            ->pluck('warehouse_id');

        $total = 0;

        foreach ($warehouseIds as $warehouseId) {
            $total += $this->latestBalance($itemId, $warehouseId);
        }

        return $total;
    }

    /**
     * Identical to latestBalance() minus the row lock — for display-only
     * reads (e.g. a draft document's snapshot of "what does the system
     * currently say") where taking FOR UPDATE would hold a lock for no
     * real reason and risks contention between unrelated concurrent drafts.
     */
    public function latestBalanceUnlocked(string $itemId, string $warehouseId): int
    {
        return (int) $this->model->query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('posting_datetime')
            ->orderByDesc('created_at')
            ->value('balance_qty') ?? 0;
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
     * do we have, where." No balances table exists; balance is always the
     * latest stock_ledgers row per pair. A paginated report across every
     * pair can't loop latestBalance() per pair like totalBalanceForItem()
     * does for one item (N+1, doesn't paginate) — instead rank rows per
     * pair by recency and keep only rn=1, then join items/warehouses for
     * filtering and display.
     */
    public function currentBalances(array $filters, int $perPage = 15)
    {
        $ranked = $this->model->query()
            ->select('item_id', 'warehouse_id', 'balance_qty')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY item_id, warehouse_id ORDER BY posting_datetime DESC, id DESC) as rn');

        $latest = DB::query()->fromSub($ranked, 'ranked')->where('rn', 1);

        return DB::query()
            ->fromSub($latest, 'latest_balances')
            ->join('items', 'items.id', '=', 'latest_balances.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'latest_balances.warehouse_id')
            ->join('uoms', 'uoms.id', '=', 'items.uom_id')
            ->select([
                'latest_balances.item_id',
                'items.item_code',
                'items.item_name',
                'latest_balances.warehouse_id',
                'warehouses.name as warehouse_name',
                'latest_balances.balance_qty as current_qty',
                'uoms.name as uom',
            ])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('latest_balances.warehouse_id', $warehouseId))
            ->when($filters['item_group_id'] ?? null, fn ($query, $itemGroupId) => $query->where('items.item_group_id', $itemGroupId))
            ->when($filters['item_id'] ?? null, fn ($query, $itemId) => $query->where('latest_balances.item_id', $itemId))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('items.item_code', 'like', "%{$search}%")
                    ->orWhere('items.item_name', 'like', "%{$search}%")
            ))
            ->orderBy('items.item_code')
            ->orderBy('warehouses.name')
            ->paginate($perPage);
    }
}
