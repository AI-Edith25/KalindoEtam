<?php

namespace App\Repositories;

use App\Models\FifoLayer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FifoLayerRepository extends BaseRepository
{
    public function __construct(FifoLayer $model)
    {
        parent::__construct($model);
    }

    private function filtered($query, array $filters)
    {
        return $query
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('fifo_layers.warehouse_id', $warehouseId))
            ->when($filters['item_id'] ?? null, fn ($q, $itemId) => $q->where('fifo_layers.item_id', $itemId))
            ->when($filters['item_group_id'] ?? null, fn ($q, $groupId) => $q->where('items.item_group_id', $groupId))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('fifo_layers.received_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('fifo_layers.received_date', '<=', $date))
            ->when(
                ! in_array($filters['hide_exhausted'] ?? null, [null, '', '0', 'false'], true),
                fn ($q) => $q->where('fifo_layers.qty_remaining', '>', 0),
            );
    }

    /**
     * One row per (item, warehouse) pair with any matching layer — the FIFO Layers report's
     * grouped view. item_code/item_name/warehouse_name are functionally dependent on the
     * grouped ids (one item has exactly one code), so including them in the GROUP BY alongside
     * the ids is valid ANSI SQL, not an aggregate-vs-ungrouped-column violation.
     */
    public function distinctItemWarehousePairs(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->model->query()
            ->join('items', 'items.id', '=', 'fifo_layers.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'fifo_layers.warehouse_id')
            ->select([
                'fifo_layers.item_id',
                'fifo_layers.warehouse_id',
                'items.item_code',
                'items.item_name',
                'warehouses.name as warehouse_name',
            ]);

        return $this->filtered($query, $filters)
            ->groupBy('fifo_layers.item_id', 'fifo_layers.warehouse_id', 'items.item_code', 'items.item_name', 'warehouses.name')
            ->orderBy('items.item_code')
            ->paginate($perPage);
    }

    /** Every layer for the given (item, warehouse) pairs — the current page's drill-down + subtotal source. */
    public function layersForPairs(array $itemIds, array $warehouseIds, array $filters): Collection
    {
        $query = $this->model->query()
            ->join('items', 'items.id', '=', 'fifo_layers.item_id')
            ->whereIn('fifo_layers.item_id', $itemIds)
            ->whereIn('fifo_layers.warehouse_id', $warehouseIds)
            ->select('fifo_layers.*')
            ->orderBy('fifo_layers.received_date');

        return $this->filtered($query, $filters)->get();
    }

    /** Every layer matching the filters, unpaginated — the summary cards' and export's source (always computed across the full filtered set, never just the current page). */
    public function allMatching(array $filters): Collection
    {
        $query = $this->model->query()
            ->join('items', 'items.id', '=', 'fifo_layers.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'fifo_layers.warehouse_id')
            ->select(['fifo_layers.*', 'items.item_code', 'items.item_name', 'warehouses.name as warehouse_name'])
            ->orderBy('items.item_code')
            ->orderBy('fifo_layers.received_date');

        return $this->filtered($query, $filters)->get();
    }

    /**
     * Candidate layers for consumption, oldest-first, locked for the
     * duration of the caller's transaction — same lockForUpdate()
     * convention as StockLedgerRepository::latestBalance(), so two
     * concurrent OUT transactions against the same item+warehouse can never
     * both read the same "available" qty_remaining.
     */
    public function candidatesForConsumption(string $itemId, string $warehouseId): Collection
    {
        return $this->model->query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();
    }

    /** Same oldest-first order as candidatesForConsumption(), without the lock — a non-mutating preview never needs to block a concurrent writer. */
    public function candidatesForPreview(string $itemId, string $warehouseId): Collection
    {
        return $this->model->query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_date')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Every layer that could contribute to a period valuation report — i.e. received on or
     * before the period's end date. Opening/closing balances are computed by the caller from
     * this set plus each layer's consumption history, not by date-filtering received_date to
     * the period itself (a layer received before the period can still hold the opening balance).
     */
    public function layersForValuation(array $filters, string $dateTo): Collection
    {
        $query = $this->model->query()
            ->join('items', 'items.id', '=', 'fifo_layers.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'fifo_layers.warehouse_id')
            ->select(['fifo_layers.*', 'items.item_code', 'items.item_name', 'warehouses.name as warehouse_name'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('fifo_layers.warehouse_id', $warehouseId))
            ->when($filters['item_id'] ?? null, fn ($q, $itemId) => $q->where('fifo_layers.item_id', $itemId))
            ->when($filters['item_group_id'] ?? null, fn ($q, $groupId) => $q->where('items.item_group_id', $groupId))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($sub) => $sub->where('items.item_code', 'like', "%{$search}%")->orWhere('items.item_name', 'like', "%{$search}%"),
            ))
            ->whereDate('fifo_layers.received_date', '<=', $dateTo)
            ->orderBy('items.item_code')
            ->orderBy('fifo_layers.received_date');

        return $query->get();
    }

    /** Every layer a given voucher created — used by reverseReceipt(). */
    public function bySource(string $sourceType, string $sourceId): Collection
    {
        return $this->model->query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->get();
    }
}
