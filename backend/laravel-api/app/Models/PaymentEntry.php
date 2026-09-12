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
        'cash_account_id',
        'branch_id',
        'reference_number',
        'remarks',
        'total_amount',
        'allocated_amount',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'payment_type' => PaymentEntryType::class,
        'payment_method' => PaymentMethod::class,
        'payment_date' => 'date',
        'total_amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
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

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentEntryAllocation::class);
    }

    /**
     * General-expense-purpose lines — only ever populated for payment_type=mixed. Supplier-bill
     * lines of a mixed voucher live in `items` above (same PaymentEntryAllocation table a plain
     * supplier voucher uses), never here — see PaymentEntryExpenseLine's own doc comment for why
     * these stay two separate tables instead of one unified line table.
     */
    public function expenseLines(): HasMany
    {
        return $this->hasMany(PaymentEntryExpenseLine::class);
    }

    /**
     * Cache-derived, computed not stored — see allocated_amount's column
     * comment. Mirrors ReceiptEntry::unallocatedAmount() exactly. Only
     * meaningful for payment_type=supplier; General Expense payments never
     * accumulate allocated_amount (there's no payable to allocate against),
     * so this would just always equal total_amount for them — callers
     * (PaymentEntryResource, the list page) gate on payment_type/status
     * themselves rather than this method special-casing it.
     */
    public function unallocatedAmount(): float
    {
        return (float) $this->total_amount - (float) $this->allocated_amount;
    }

    /**
     * Cr Cash/Bank always; Dr side depends on payment_type — Advance to
     * Suppliers (1250, a suspense asset) for a Supplier payment, or the
     * chosen Expense account for a General Expense payment (no
     * Supplier/PO involved at all, so no suspense leg either — it's posted
     * straight to the expense). For AccountingService::postForDocument()
     * to post (see PaymentEntryService::submit()). Same shape/purpose as
     * ReceiptEntry::journalLines(): paying and applying to a specific bill
     * are separate operations now; see PaymentEntryAllocation::
     * journalLines() for the second leg.
     */
    public function journalLines(): array
    {
        if ($this->payment_type === PaymentEntryType::GENERAL_EXPENSE) {
            return [
                ['account' => $this->expenseAccount->code, 'type' => 'debit', 'amount' => (float) $this->total_amount],
                ['account' => $this->cashAccount->code, 'type' => 'credit', 'amount' => (float) $this->total_amount],
            ];
        }

        if ($this->payment_type === PaymentEntryType::MIXED) {
            return $this->mixedJournalLines();
        }

        return [
            ['account' => '1250', 'type' => 'debit', 'amount' => (float) $this->total_amount], // Advance to Suppliers
            ['account' => $this->cashAccount->code, 'type' => 'credit', 'amount' => (float) $this->total_amount],
        ];
    }

    /**
     * One aggregate journal for the whole mixed voucher: a single credit to cash/bank for
     * total_amount (the one real bank movement this feature exists to match), plus one debit
     * leg per line — supplier lines debit 1250 (Advance to Suppliers), same account the plain
     * supplier flow's own aggregate journal already uses, and expense lines debit their own
     * expense_account. This is IN ADDITION TO, not instead of, the existing per-allocation
     * `Dr 2000/Cr 1250` journal each supplier line's own PaymentEntryAllocation::journalLines()
     * posts (see PaymentEntryService::submit()'s MIXED branch) — that second journal is what
     * PaymentEntryAllocationController::reverse() looks up to undo a single line later; folding
     * a supplier line's debit straight into this aggregate journal instead would leave nothing
     * for a future per-line reversal to find. Net accounting effect per supplier line across
     * both journals is still "Dr Accounts Payable / Cr Cash", just via the same two-hop the
     * existing supplier-only flow already uses.
     */
    private function mixedJournalLines(): array
    {
        $lines = [];
        $applied = 0.0;

        foreach ($this->items as $allocation) {
            $lines[] = ['account' => '1250', 'type' => 'debit', 'amount' => (float) $allocation->allocated_amount];
            $applied += (float) $allocation->allocated_amount;
        }

        foreach ($this->expenseLines as $expenseLine) {
            $lines[] = ['account' => $expenseLine->expenseAccount->code, 'type' => 'debit', 'amount' => (float) $expenseLine->amount];
            $applied += (float) $expenseLine->amount;
        }

        // Any shortfall between total_amount and the lines actually applied is money that left
        // the bank but hasn't been assigned a purpose yet — same "unapplied" concept the plain
        // supplier flow already has (its own aggregate journal always debits the FULL
        // total_amount to 1250 regardless of how much gets allocated afterward). Debiting the
        // remainder here too is what keeps this journal balanced against the one credit line,
        // which must always equal the full total_amount per the ticket's own requirement.
        $remainder = round((float) $this->total_amount - $applied, 2);
        if ($remainder > 0) {
            $lines[] = ['account' => '1250', 'type' => 'debit', 'amount' => $remainder];
        }

        $lines[] = ['account' => $this->cashAccount->code, 'type' => 'credit', 'amount' => (float) $this->total_amount];

        return $lines;
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
