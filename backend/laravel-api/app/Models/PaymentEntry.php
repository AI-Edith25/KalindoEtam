<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentEntryType;
use App\Enums\PaymentMethod;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentEntry extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'supplier_id',
        'payment_type',
        'expense_account_id',
        'description',
        'payment_date',
        'payment_method',
        'reference_number',
        'remarks',
        'total_amount',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'payment_type' => PaymentEntryType::class,
        'payment_method' => PaymentMethod::class,
        'payment_date' => 'date',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'payment';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentEntryItem::class);
    }

    /**
     * Cr Cash/Bank always; Dr side depends on payment_type — Accounts
     * Payable for a Supplier payment (settling a payable already posted by
     * GoodsReceipt::journalLines()), or the chosen Expense account for a
     * General Expense payment (no Supplier/PO involved at all). For
     * AccountingService::postForDocument() to post (see
     * PaymentEntryService::submit()). Same shape/purpose as
     * ReceiptEntry::journalLines().
     */
    public function journalLines(): array
    {
        if ($this->payment_type === PaymentEntryType::GENERAL_EXPENSE) {
            return [
                ['account' => $this->expenseAccount->code, 'type' => 'debit', 'amount' => (float) $this->total_amount],
                ['account' => $this->cashAccountCode(), 'type' => 'credit', 'amount' => (float) $this->total_amount],
            ];
        }

        return [
            ['account' => '2000', 'type' => 'debit', 'amount' => (float) $this->total_amount], // Accounts Payable
            ['account' => $this->cashAccountCode(), 'type' => 'credit', 'amount' => (float) $this->total_amount],
        ];
    }

    /**
     * Every PaymentMethod case is listed explicitly (not a wildcard
     * default) so adding a new case forces a decision here — mirrors
     * ReceiptEntry::cashAccountCode() exactly; today they all clear to one
     * account.
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
     * A submitted Payment Entry has already reduced one or more Accounts
     * Payable balances. Reversing that safely needs a dedicated void/undo
     * workflow, which does not exist yet — same rationale as
     * GoodsReceipt::cancel() (Sprint 4) and Delivery::cancel() (Sprint 5).
     */
    public function cancel(): static
    {
        throw new BusinessException('Payment Entry cannot be cancelled. Reversal is not yet implemented.');
    }
}
