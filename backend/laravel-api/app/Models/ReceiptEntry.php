<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReceiptEntry extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'customer_id',
        'receipt_date',
        'payment_method',
        'reference_number',
        'remarks',
        'total_amount',
        'allocated_amount',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'payment_method' => PaymentMethod::class,
        'receipt_date' => 'date',
        'total_amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'receipt';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Cache-derived, computed not stored — see allocated_amount's column
     * comment. The one place this subtraction happens; PaymentAllocationService
     * reuses this instead of recomputing it.
     */
    public function unallocatedAmount(): float
    {
        return (float) $this->total_amount - (float) $this->allocated_amount;
    }

    /**
     * Dr Cash and Bank, Cr Unapplied Customer Payments — for AccountingService::
     * postForDocument() to post (see ReceiptEntryService::submit()). Same
     * shape/purpose as Invoice::journalLines(). Always this suspense account,
     * never Accounts Receivable directly — receiving money and applying it
     * to a specific invoice are separate operations; see
     * PaymentAllocation::journalLines() for the second leg.
     */
    public function journalLines(): array
    {
        return [
            ['account' => $this->cashAccountCode(), 'type' => 'debit', 'amount' => (float) $this->total_amount],
            ['account' => '1150', 'type' => 'credit', 'amount' => (float) $this->total_amount], // Unapplied Customer Payments
        ];
    }

    /**
     * Every PaymentMethod case is listed explicitly (not a wildcard
     * default) so adding a new case forces a decision here — today they
     * all clear to one account.
     * ponytail: split Bank Transfer/QRIS/Credit Card/Cheque into their own
     * accounts once bank reconciliation needs the distinction.
     */
    protected function cashAccountCode(): string
    {
        return match ($this->payment_method) {
            PaymentMethod::CASH,
            PaymentMethod::BANK_TRANSFER,
            PaymentMethod::CHEQUE,
            PaymentMethod::QRIS,
            PaymentMethod::CREDIT_CARD => '1100', // Cash and Bank
        };
    }

    /**
     * A submitted Receipt Entry has posted a Dr Cash/Cr Unapplied Customer
     * Payments journal, and may already have one or more PaymentAllocations
     * against it. Reversing that safely needs a dedicated void/undo
     * workflow, which does not exist yet — same rationale as
     * GoodsReceipt::cancel() (Sprint 4) and Delivery::cancel() (Sprint 5).
     */
    public function cancel(): static
    {
        throw new BusinessException('Receipt Entry cannot be cancelled. Reversal is not yet implemented.');
    }
}
