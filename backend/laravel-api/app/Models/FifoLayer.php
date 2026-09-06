<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per receiving event per item+warehouse — created by
 * FifoLayerService::receive(), consumed oldest-first by ::consume(). See
 * FifoLayerService's own docblock for the full write-path map.
 */
class FifoLayer extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'source_type',
        'source_id',
        'source_document_number',
        'received_date',
        'qty_in',
        'qty_remaining',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'received_date' => 'date',
        'qty_in' => 'decimal:4',
        'qty_remaining' => 'decimal:4',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(FifoLayerConsumption::class);
    }
}
