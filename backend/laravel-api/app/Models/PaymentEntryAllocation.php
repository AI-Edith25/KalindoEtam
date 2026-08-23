<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evolves PaymentEntryItem — the fact that some amount of a specific
 * Payment (PaymentEntry) was applied to a specific supplier bill's payable,
 * on a specific date. Not Documentable: it's a single atomic fact created
 * by PaymentEntryAllocationService::allocateBatch(), not a numbered,
 * drafted document. is_reversed (not soft-delete alone) is what marks a
 * corrected allocation, so it stays visible in history instead of
 * disappearing — see PaymentEntryAllocationService::reverse(). Mirrors
 * PaymentAllocation (the AR side) field-for-field.
 */
class PaymentEntryAllocation extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'payment_entry_id',
        'accounts_payable_id',
        'allocated_amount',
        'allocation_date',
        'is_reversed',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'allocation_date' => 'date',
        'is_reversed' => 'boolean',
    ];

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(PaymentEntry::class);
    }

    public function accountsPayable(): BelongsTo
    {
        return $this->belongsTo(AccountsPayable::class);
    }

    /**
     * Dr Accounts Payable, Cr Advance to Suppliers — for AccountingService::
     * postForDocument() to post (see PaymentEntryAllocationService::
     * allocateBatch()). Both legs decrease: the bill we owe (2000, a
     * liability — debiting shrinks it) and the prepayment asset we're
     * spending down (1250 — crediting shrinks it). This is the mirror
     * image of PaymentAllocation::journalLines() (Dr 1150/Cr 1200), not a
     * naive same-direction copy — there the allocation shrinks a liability
     * (1150) and an asset (1200) together too, but AP's suspense account is
     * on the *asset* side and its bill is on the *liability* side, so which
     * account takes the debit vs. the credit flips accordingly.
     */
    public function journalLines(): array
    {
        return [
            ['account' => '2000', 'type' => 'debit', 'amount' => (float) $this->allocated_amount], // Accounts Payable
            ['account' => '1250', 'type' => 'credit', 'amount' => (float) $this->allocated_amount], // Advance to Suppliers
        ];
    }
}
