<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-zone sale price override for one item. No SoftDeletes here — see the migration's
 * docblock: deleting an override just falls back to Item::standard_rate, and rate history
 * lives in audit_logs (ItemPriceService), not a deleted_at trail on this table.
 */
class ItemPrice extends Model
{
    use HasAuditTrail, HasUuids;

    protected $fillable = [
        'item_id',
        'price_zone_id',
        'rate',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function priceZone(): BelongsTo
    {
        return $this->belongsTo(PriceZone::class);
    }
}
