<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceSource;
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
        'source', 'goods_receipt_id', 'purchase_order_id', 'supplier_id',
        'invoice_date', 'due_date',
        'subtotal', 'tax_amount', 'grand_total',
        'reference_number', 'attention', 'department', 'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'source' => PurchaseInvoiceSource::class,
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
     * AP-side. tax_amount is debited here to net against Sales' output-tax
     * credit on the same shared 2100 Tax Payable account.
     *
     * Built from line presence, not from $this->source directly — this naturally covers
     * GOODS_RECEIPT (stock lines only), DIRECT (non-stock lines only), and MIXED (both) with the
     * same two algorithms run side by side instead of three branches: stock lines post a single
     * aggregate Dr 5100 (Goods Receipt items carry no per-line expense account); non-stock lines
     * post one Dr/Cr line per distinct Chart-of-Accounts account chosen on the invoice (items
     * must be loaded with chartOfAccount) — Expense is debited (adds to what's owed), Liability is
     * credited (e.g. Hutang PPh 23 deducts from what's owed; its group sum is already negative,
     * per PurchaseInvoiceService::buildDirectLines()).
     */
    public function journalLines(): array
    {
        $this->loadMissing('items.chartOfAccount');

        $stockItems = $this->items->where('line_type', 'stock');
        $nonStockItems = $this->items->where('line_type', 'non_stock');

        $lines = [];

        if ($stockItems->isNotEmpty()) {
            $lines[] = ['account' => '5100', 'type' => 'debit', 'amount' => (float) $stockItems->sum('amount')]; // Purchase Expense
        }

        foreach ($nonStockItems->groupBy('chart_of_account_id') as $group) {
            $account = $group->first()->chartOfAccount;
            $amount = (float) $group->sum('amount');

            $lines[] = $account->account_type === AccountType::LIABILITY
                ? ['account' => $account->code, 'type' => 'credit', 'amount' => abs($amount)]
                : ['account' => $account->code, 'type' => 'debit', 'amount' => $amount];
        }

        $lines[] = ['account' => '210.01', 'type' => 'credit', 'amount' => (float) $this->grand_total]; // Accounts Payable

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '213.01', 'type' => 'debit', 'amount' => (float) $this->tax_amount]; // Tax Payable
        }

        return $lines;
    }
}
