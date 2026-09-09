<?php

namespace App\Repositories;

use App\Models\FifoLayerConsumption;
use Illuminate\Support\Collection;

class FifoLayerConsumptionRepository extends BaseRepository
{
    public function __construct(FifoLayerConsumption $model)
    {
        parent::__construct($model);
    }

    /** Not-yet-reversed consumption rows for a voucher, locked — used by reverseConsumption(). */
    public function unreversedForSource(string $consumingSourceType, string $consumingSourceId): Collection
    {
        return $this->model->query()
            ->where('consuming_source_type', $consumingSourceType)
            ->where('consuming_source_id', $consumingSourceId)
            ->where('reversed', false)
            ->lockForUpdate()
            ->get();
    }

    /** Every consumption row for a voucher, scoped to one item — used by averageConsumedCost(). */
    public function forSourceAndItem(string $consumingSourceType, string $consumingSourceId, string $itemId): Collection
    {
        return $this->model->query()
            ->where('consuming_source_type', $consumingSourceType)
            ->where('consuming_source_id', $consumingSourceId)
            ->whereHas('fifoLayer', fn ($q) => $q->where('item_id', $itemId))
            ->get();
    }

    /**
     * Per-layer consumption totals at two cutoffs, keyed by fifo_layer_id — the building blocks
     * for a layer's remaining qty at the start and end of a valuation period. before_from is
     * everything consumed strictly before the period start (still "remaining" at opening);
     * through_to is everything consumed through the end of the period's last day (what's left
     * at closing). Reversed consumptions never happened, so they're excluded from both.
     */
    public function boundedSumsForLayers(array $layerIds, string $dateFrom, string $dateTo): Collection
    {
        if ($layerIds === []) {
            return collect();
        }

        return $this->model->query()
            ->whereIn('fifo_layer_id', $layerIds)
            ->where('reversed', false)
            ->selectRaw(
                'fifo_layer_id,
                SUM(CASE WHEN created_at < ? THEN qty_consumed ELSE 0 END) as before_from,
                SUM(CASE WHEN created_at < ? THEN qty_consumed ELSE 0 END) as through_to',
                [
                    $dateFrom.' 00:00:00',
                    \Carbon\Carbon::parse($dateTo)->addDay()->toDateString().' 00:00:00',
                ],
            )
            ->groupBy('fifo_layer_id')
            ->get()
            ->keyBy('fifo_layer_id');
    }
}
