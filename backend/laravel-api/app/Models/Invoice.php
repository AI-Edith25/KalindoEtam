<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'invoice_type',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'delivery_id',
        'sales_order_id',
        'customer_id',
        'invoice_date',
        'due_date',
        'subtotal',
        'discount_amount',
        'discount_type',
        'discount_percentage',
        'tax_id',
        'tax_amount',
        'grand_total',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'invoice_type' => InvoiceType::class,
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_type' => DiscountType::class,
        'discount_percentage' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Sprint 2 (Invoice Numbering): Goods and Transportation each number
     * independently — 'invoice_goods' is the historical 'invoice' series
     * renamed in place (see naming_series migration), so every already-
     * generated document_number and its counter carry over unchanged.
     */
    public function documentType(): string
    {
        return match ($this->invoice_type) {
            InvoiceType::TRANSPORTATION => 'invoice_transportation',
            default => 'invoice_goods',
        };
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The Tax this invoice's tax_amount was calculated from, if any (docs/TAX_ENGINE_DESIGN.md §5) — never null when tax_amount > 0 and a Tax was actually selected, but tax_amount can still be a legacy raw value with no tax_id. */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function accountsReceivable(): HasOne
    {
        return $this->hasOne(AccountsReceivable::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class);
    }

    /**
     * Debit/credit breakdown derived from already-stored fields, for
     * AccountingService::postForDocument() to post (see InvoiceService::
     * submit()). No persistence, no ledger access — Invoice only prepares
     * the data. Account references are chart_of_accounts codes, not
     * names, since names are a display concern and can be renamed.
     */
    public function journalLines(): array
    {
        $lines = [
            ['account' => '1200', 'type' => 'debit', 'amount' => (float) $this->grand_total],  // Accounts Receivable
            ['account' => '4000', 'type' => 'credit', 'amount' => (float) $this->subtotal],     // Sales Revenue
        ];

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '2100', 'type' => 'credit', 'amount' => (float) $this->tax_amount]; // Tax Payable
        }

        if ((float) $this->discount_amount > 0) {
            $lines[] = ['account' => '4900', 'type' => 'debit', 'amount' => (float) $this->discount_amount]; // Discount Given
        }

        return $lines;
    }
}
