<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number', 'status', 'revision', 'submitted_at', 'cancelled_at',
        'goods_receipt_id', 'purchase_order_id', 'supplier_id',
        'invoice_date', 'due_date',
        'subtotal', 'tax_amount', 'grand_total',
        'reference_number', 'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'purchase_invoice';
    }

    /** The anchor/primary Goods Receipt (earliest receipt_date among the sources) — kept for backward compatibility. See goodsReceipts() for the full source history. */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** Authoritative full source history — every Goods Receipt this Invoice was created from, one or many. */
    public function goodsReceipts(): BelongsToMany
    {
        return $this->belongsToMany(GoodsReceipt::class, 'purchase_invoice_goods_receipts');
    }

    /** The anchor/primary Purchase Order (of the anchor Goods Receipt) — kept for backward compatibility. See purchaseOrders() for the full source history. */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Authoritative full source history — every Purchase Order behind the source Goods Receipts, one or many. */
    public function purchaseOrders(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrder::class, 'purchase_invoice_purchase_orders');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function accountsPayable(): HasOne
    {
        return $this->hasOne(AccountsPayable::class, 'invoice_id');
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * Debit/credit breakdown derived from already-stored fields, for
     * AccountingService::postForDocument() to post (see
     * PurchaseInvoiceService::submit()). Mirrors Invoice::journalLines(),
     * AP-side. tax_amount is a manual header figure (Goods Receipt items
     * carry no tax snapshot to derive it from), debited here to net against
     * Sales' output-tax credit on the same shared 2100 Tax Payable account.
     */
    public function journalLines(): array
    {
        $lines = [
            ['account' => '5100', 'type' => 'debit', 'amount' => (float) $this->subtotal],   // Purchase Expense
            ['account' => '2000', 'type' => 'credit', 'amount' => (float) $this->grand_total], // Accounts Payable
        ];

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '2100', 'type' => 'debit', 'amount' => (float) $this->tax_amount]; // Tax Payable
        }

        return $lines;
    }
}
