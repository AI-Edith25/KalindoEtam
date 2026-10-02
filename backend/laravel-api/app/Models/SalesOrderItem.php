<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrderItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'sales_order_id',
        'item_id',
        'uom_id',
        'uom_factor',
        'qty',
        'rate',
        'amount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'net_amount',
        'delivered_qty',
        'tax_id',
        'tax_amount',
    ];

    protected $casts = [
        'uom_factor' => 'decimal:6',
        'qty' => 'integer',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'delivered_qty' => 'integer',
        'tax_amount' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The line's chosen UOM; null = the item's base UOM. qty/rate/delivered_qty are all in it. */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /** Any row here means a Delivery already references this line — see SalesOrderService::syncApprovedItems(). */
    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
