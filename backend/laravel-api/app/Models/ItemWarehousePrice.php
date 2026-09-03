<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-warehouse sale price override for one item. No SoftDeletes — see the migration's
 * docblock: deleting an override falls back to Item::standard_rate (or the Main warehouse's
 * resolved price, via ItemPriceResolver), and rate history lives in audit_logs, not a
 * deleted_at trail on this table. Same shape as ItemPrice (Price Zone).
 */
class ItemWarehousePrice extends Model
{
    use HasAuditTrail, HasUuids;

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'rate',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
