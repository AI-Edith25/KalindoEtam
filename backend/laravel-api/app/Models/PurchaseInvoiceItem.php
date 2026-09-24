<?php

namespace App\Models;

use App\Enums\QtyCategory;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoiceItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'purchase_invoice_id', 'goods_receipt_item_id', 'item_id', 'chart_of_account_id',
        'item_code', 'item_name', 'uom', 'rate', 'qty', 'qty_category', 'amount',
        'tax_id', 'tax_amount',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'qty' => 'decimal:4',
        'qty_category' => QtyCategory::class,
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Direct/Non-Stock lines only — the expense account this line posts to. Null for a Goods Receipt-sourced line. */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function purchaseReturnItems(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}
