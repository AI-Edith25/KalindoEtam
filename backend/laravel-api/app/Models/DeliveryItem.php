<?php

namespace App\Models;

use App\Enums\QtyCategory;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'delivery_id',
        'sales_order_item_id',
        'item_id',
        'item_code',
        'item_name',
        'uom',
        'uom_factor',
        'rate',
        'qty',
        'qty_category',
        'amount',
        'tax_id',
        'tax_amount',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'qty' => 'decimal:4',
        'qty_category' => QtyCategory::class,
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    /** Qty in the item's base (stock) UOM — what the Stock Ledger and FIFO layers actually hold. */
    public function baseQty(): float
    {
        return round((float) $this->qty * (float) ($this->uom_factor ?? 1), 4);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /** Null once this line has never been invoiced — see restrictOnDelete on invoice_items.delivery_item_id, and DeliveryService::updateComplete()'s row-removal guard. */
    public function invoiceItem(): HasOne
    {
        return $this->hasOne(InvoiceItem::class);
    }
}
