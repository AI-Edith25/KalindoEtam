<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'delivery_item_id',
        'item_id',
        'item_code',
        'item_name',
        'uom',
        'uom_factor',
        'rate',
        'qty',
        'amount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'net_amount',
        'unit_cost',
        'cost_amount',
        'tax_id',
        'tax_amount',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'qty' => 'integer',
        'amount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'unit_cost' => 'decimal:6',
        'cost_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    /** Qty in the item's base (stock) UOM — unit_cost is per base unit, so COGS = unit_cost × this. */
    public function baseQty(): float
    {
        return round((float) $this->qty * (float) ($this->uom_factor ?? 1), 4);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function creditNoteItems(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function debitNoteItems(): HasMany
    {
        return $this->hasMany(DebitNoteItem::class);
    }
}
