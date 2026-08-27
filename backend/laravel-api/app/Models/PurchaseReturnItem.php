<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One returned line against a specific PurchaseInvoiceItem. Not
 * Documentable — a line fact owned by its PurchaseReturn, same category as
 * PurchaseInvoiceItem. Unlike CreditNoteItem's `restock` (intent only,
 * never wired), a purchase return always moves real stock when
 * qty_returned > 0 — see PurchaseReturnService::submit().
 */
class PurchaseReturnItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'purchase_return_id', 'purchase_invoice_item_id', 'item_id', 'warehouse_id',
        'item_code', 'item_name', 'uom', 'qty_returned', 'rate', 'amount',
    ];

    protected $casts = [
        'qty_returned' => 'integer',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
