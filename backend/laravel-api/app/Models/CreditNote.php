<?php

namespace App\Models;

use App\Enums\CreditNoteReason;
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
 * Corrects an already-posted Invoice — the only accounting-correction path
 * for a submitted Invoice (see InvoiceService::cancel(), which deliberately
 * never touches the ledger). One Invoice can have many Credit Notes over
 * time; each is validated against what's still creditable, not the
 * Invoice's original total. See docs/CREDIT_NOTE_DESIGN.md.
 */
class CreditNote extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number', 'status', 'revision', 'submitted_at', 'cancelled_at',
        'invoice_id', 'customer_id',
        'credit_note_date', 'reason',
        'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'remarks',
        'is_reversed', 'reversed_at',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'reason' => CreditNoteReason::class,
        'credit_note_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'credit_note';
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
        return $this->hasMany(CreditNoteItem::class);
    }

    /**
     * Mirrors Invoice::journalLines() with every debit/credit swapped —
     * this Credit Note's own subtotal/discount_amount/tax_amount/total_amount
     * represent the portion being reversed, not the Invoice's full totals.
     * Dedicated contra-revenue account (4050), never a direct debit to
     * 4000 Sales Revenue — see docs/CREDIT_NOTE_DESIGN.md §5 and the
     * approved Sprint 13 decision.
     */
    public function journalLines(): array
    {
        $lines = [
            ['account' => '1200', 'type' => 'credit', 'amount' => (float) $this->total_amount], // Accounts Receivable, reduced
        ];

        if ((float) $this->subtotal > 0) {
            $lines[] = ['account' => '4050', 'type' => 'debit', 'amount' => (float) $this->subtotal]; // Sales Returns and Allowances
        }

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '2100', 'type' => 'debit', 'amount' => (float) $this->tax_amount]; // Tax Payable, reduced
        }

        if ((float) $this->discount_amount > 0) {
            $lines[] = ['account' => '4900', 'type' => 'credit', 'amount' => (float) $this->discount_amount]; // Discount Given, reduced
        }

        return $lines;
    }

    /**
     * A submitted Credit Note is a correction that has already posted to
     * the ledger and (for reason=returned_goods lines) is flagged for a
     * future inventory return. Undoing it is CreditNoteService::reverse(),
     * not cancel() — same precedent as Delivery/ReceiptEntry/JournalEntry.
     */
    public function cancel(): static
    {
        throw new BusinessException('Credit Note cannot be cancelled. Use reverse() to correct a posted note.');
    }
}
