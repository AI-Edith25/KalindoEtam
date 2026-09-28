<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An extra UOM for an Item; conversion_factor = how many base UOM units one of this UOM holds. */
class ItemUom extends Model
{
    use HasAuditTrail, HasUuids;

    protected $fillable = ['item_id', 'uom_id', 'conversion_factor'];

    protected $casts = ['conversion_factor' => 'decimal:6'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }
}
