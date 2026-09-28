<?php

namespace App\Models;

use App\Enums\QtyCategory;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'uom_id',
        'uom_factor',
        'qty',
        'qty_category',
        'rate',
        'amount',
        'received_qty',
        'tax_id',
        'tax_amount',
    ];

    protected $casts = [
        'uom_factor' => 'decimal:6',
        'qty' => 'decimal:4',
        'qty_category' => QtyCategory::class,
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'received_qty' => 'decimal:4',
        'tax_amount' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The line's chosen UOM; null = the item's base UOM. */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
