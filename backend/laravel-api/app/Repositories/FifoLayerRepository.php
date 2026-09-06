<?php

namespace App\Repositories;

use App\Models\FifoLayer;
use Illuminate\Support\Collection;

class FifoLayerRepository extends BaseRepository
{
    public function __construct(FifoLayer $model)
    {
        parent::__construct($model);
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

    /** Every layer a given voucher created — used by reverseReceipt(). */
    public function bySource(string $sourceType, string $sourceId): Collection
    {
        return $this->model->query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->get();
    }
}
