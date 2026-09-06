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
}
