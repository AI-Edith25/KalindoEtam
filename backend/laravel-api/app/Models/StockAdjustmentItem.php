<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustmentItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'stock_adjustment_id',
        'item_id',
        'item_code',
        'item_name',
        'uom',
        'system_qty',
        'counted_qty',
        'difference_qty',
        'reason',
    ];

    protected $casts = [
        'system_qty' => 'integer',
        'counted_qty' => 'integer',
        'difference_qty' => 'integer',
    ];

    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
