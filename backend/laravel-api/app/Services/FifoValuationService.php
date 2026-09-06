<?php

namespace App\Services;

use App\Repositories\FifoLayerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read-only reporting over FifoLayer — "what do we have, at what cost, right now." Never
 * writes; FifoLayerService owns every mutation. Two views over the same filtered layer set:
 * grouped() for the on-screen per-item/warehouse table (paginated by pair, each with its own
 * drill-down layers + weighted-average cost), and summary() for the three header cards —
 * always computed across every matching layer, never just the current page.
 */
class FifoValuationService
{
    public function __construct(
        protected FifoLayerRepository $fifoLayerRepository,
    ) {}

    /** @return LengthAwarePaginator<int, object{item_id: string, warehouse_id: string, item_code: string, item_name: string, warehouse_name: string, qty_remaining: float, total_value: float, weighted_average_cost: float, layers: Collection}> */
    public function grouped(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $pairs = $this->fifoLayerRepository->distinctItemWarehousePairs($filters, $perPage);

        $itemIds = collect($pairs->items())->pluck('item_id')->unique()->values()->all();
        $warehouseIds = collect($pairs->items())->pluck('warehouse_id')->unique()->values()->all();

        $layers = $itemIds === []
            ? collect()
            : $this->fifoLayerRepository->layersForPairs($itemIds, $warehouseIds, $filters);

        $pairs->getCollection()->transform(function ($pair) use ($layers) {
            $pairLayers = $layers
                ->filter(fn ($layer) => $layer->item_id === $pair->item_id && $layer->warehouse_id === $pair->warehouse_id)
                ->values();

            return $this->summarizePair($pair->item_id, $pair->warehouse_id, $pair->item_code, $pair->item_name, $pair->warehouse_name, $pairLayers);
        });

        return $pairs;
    }

    public function summary(array $filters): array
    {
        $layers = $this->fifoLayerRepository->allMatching($filters);

        return [
            'total_value' => round((float) $layers->sum(fn ($layer) => (float) $layer->qty_remaining * (float) $layer->unit_cost), 2),
            'item_count' => $layers->pluck('item_id')->unique()->count(),
            'total_qty' => (float) $layers->sum(fn ($layer) => (float) $layer->qty_remaining),
        ];
    }

    /** Every matching layer, flat (one row each) with item/warehouse names attached — the export's row set, same filters as the screen. */
    public function exportRows(array $filters): Collection
    {
        return $this->fifoLayerRepository->allMatching($filters);
    }

    private function summarizePair(string $itemId, string $warehouseId, string $itemCode, string $itemName, string $warehouseName, Collection $layers): object
    {
        $qtyRemaining = (float) $layers->sum(fn ($layer) => (float) $layer->qty_remaining);
        $totalValue = round((float) $layers->sum(fn ($layer) => (float) $layer->qty_remaining * (float) $layer->unit_cost), 2);

        return (object) [
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'warehouse_name' => $warehouseName,
            'qty_remaining' => $qtyRemaining,
            'total_value' => $totalValue,
            'weighted_average_cost' => $qtyRemaining > 0 ? round($totalValue / $qtyRemaining, 2) : 0.0,
            'layers' => $layers,
        ];
    }
}
