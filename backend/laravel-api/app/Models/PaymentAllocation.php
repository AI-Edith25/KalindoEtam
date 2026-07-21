<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evolves ReceiptEntryItem (Sprint 6) — the fact that some amount of a
 * specific Payment (ReceiptEntry) was applied to a specific Invoice's
 * receivable, on a specific date. Not Documentable: it's a single atomic
 * fact created by PaymentAllocationService::allocateBatch(), not a
 * numbered, drafted document. is_reversed (not soft-delete alone) is what
 * marks a corrected allocation, so it stays visible in history instead of
 * disappearing — see PaymentAllocationService::reverse().
 */
class PaymentAllocation extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'receipt_entry_id',
        'accounts_receivable_id',
        'allocated_amount',
        'allocation_date',
        'is_reversed',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'allocation_date' => 'date',
        'is_reversed' => 'boolean',
    ];

    public function receiptEntry(): BelongsTo
    {
        return $this->belongsTo(ReceiptEntry::class);
    }

    public function accountsReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountsReceivable::class);
    }

    /**
     * Dr Unapplied Customer Payments, Cr Accounts Receivable — for
     * AccountingService::postForDocument() to post (see
     * PaymentAllocationService::allocateBatch()). Same shape/purpose as
     * Invoice::journalLines() / ReceiptEntry::journalLines().
     */
    public function journalLines(): array
    {
        return [
            ['account' => '1150', 'type' => 'debit', 'amount' => (float) $this->allocated_amount], // Unapplied Customer Payments
            ['account' => '1200', 'type' => 'credit', 'amount' => (float) $this->allocated_amount], // Accounts Receivable
        ];
    }
}
