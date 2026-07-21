<?php

namespace App\Models;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Increases a customer's receivable after an Invoice has already been
 * posted — under-billed quantities, price corrections, additional service
 * charges, freight, or a tax under-charge. Mirrors CreditNote's structure
 * with the amount direction reversed and no upper bound: unlike a Credit
 * Note, a Debit Note is never capped against a remaining balance, since it
 * is always adding to what's owed. See docs/DEBIT_NOTE_DESIGN.md.
 */
class DebitNote extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number', 'status', 'revision', 'submitted_at', 'cancelled_at',
        'invoice_id', 'customer_id',
        'debit_note_date', 'reason',
        'subtotal_goods', 'subtotal_other', 'tax_amount', 'total_amount', 'remarks',
        'is_reversed', 'reversed_at',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'reason' => DebitNoteReason::class,
        'debit_note_date' => 'date',
        'subtotal_goods' => 'decimal:2',
        'subtotal_other' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'debit_note';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DebitNoteItem::class);
    }

    /**
     * Same direction as Invoice::journalLines() (no debit/credit swap,
     * unlike CreditNote's reversing journal) — a Debit Note adds to what's
     * owed. subtotal_goods/subtotal_other are routed to separate accounts
     * so the account choice follows line shape, never a reason-keyed
     * branch — see docs/DEBIT_NOTE_DESIGN.md §5.
     */
    public function journalLines(): array
    {
        $lines = [
            ['account' => '1200', 'type' => 'debit', 'amount' => (float) $this->total_amount], // Accounts Receivable, increased
        ];

        if ((float) $this->subtotal_goods > 0) {
            $lines[] = ['account' => '4000', 'type' => 'credit', 'amount' => (float) $this->subtotal_goods]; // Sales Revenue
        }

        if ((float) $this->subtotal_other > 0) {
            $lines[] = ['account' => '4100', 'type' => 'credit', 'amount' => (float) $this->subtotal_other]; // Other Income
        }

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '2100', 'type' => 'credit', 'amount' => (float) $this->tax_amount]; // Tax Payable, increased
        }

        return $lines;
    }

    /**
     * A submitted Debit Note has already posted to the ledger. Undoing it
     * is DebitNoteService::reverse(), not cancel() — same precedent as
     * CreditNote/Delivery/ReceiptEntry/JournalEntry.
     */
    public function cancel(): static
    {
        throw new BusinessException('Debit Note cannot be cancelled. Use reverse() to correct a posted note.');
    }
}
