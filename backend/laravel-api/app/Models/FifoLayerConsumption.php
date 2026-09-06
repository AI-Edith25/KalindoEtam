<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail row — one per (layer touched, consuming voucher). Never
 * deleted, only flagged `reversed` by FifoLayerService::reverseConsumption().
 * This table is what makes COGS-per-voucher and exact-layer reversal
 * possible without recomputing anything.
 */
class FifoLayerConsumption extends Model
{
    use HasUuids;

    protected $fillable = [
        'fifo_layer_id',
        'consuming_source_type',
        'consuming_source_id',
        'qty_consumed',
        'unit_cost',
        'total_cost',
        'reversed',
        'reversed_at',
    ];

    protected $casts = [
        'qty_consumed' => 'decimal:4',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'reversed' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    public function fifoLayer(): BelongsTo
    {
        return $this->belongsTo(FifoLayer::class);
    }
}
