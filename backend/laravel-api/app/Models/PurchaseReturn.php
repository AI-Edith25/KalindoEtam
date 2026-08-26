<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseReturnReason;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Corrects an already-posted Purchase Invoice — the only accounting-
 * correction path for a submitted Purchase Invoice (see
 * PurchaseInvoiceService::cancel(), which deliberately never touches the
 * ledger or stock). One Purchase Invoice can have many Purchase Returns
 * over time; each is validated against what's still returnable, not the
 * Invoice's original total. Mirrors CreditNote, single document type (no
 * Purchase Debit Note equivalent — see plan Context).
 */
class PurchaseReturn extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number', 'status', 'revision', 'submitted_at', 'cancelled_at',
        'purchase_invoice_id', 'supplier_id',
        'return_date', 'reason',
        'subtotal', 'tax_amount', 'total_amount', 'remarks',
        'is_reversed', 'reversed_at',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'reason' => PurchaseReturnReason::class,
        'return_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'purchase_return';
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /**
     * Mirrors PurchaseInvoice::journalLines() with every debit/credit
     * swapped — this Return's own subtotal/tax_amount/total_amount
     * represent the portion being reversed, not the Invoice's full totals.
     * Dedicated contra-expense account (5050), never a direct credit to
     * 5100 Purchase Expense.
     */
    public function journalLines(): array
    {
        $lines = [
            ['account' => '2000', 'type' => 'debit', 'amount' => (float) $this->total_amount], // Accounts Payable, reduced
        ];

        if ((float) $this->subtotal > 0) {
            $lines[] = ['account' => '5050', 'type' => 'credit', 'amount' => (float) $this->subtotal]; // Purchase Returns and Allowances
        }

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '2100', 'type' => 'credit', 'amount' => (float) $this->tax_amount]; // Tax Payable, reduced
        }

        return $lines;
    }

    /**
     * A submitted Purchase Return is a correction that has already posted
     * to the ledger and moved stock. Undoing it is PurchaseReturnService::
     * reverse(), not cancel() — same precedent as CreditNote.
     */
    public function cancel(): static
    {
        throw new BusinessException('Purchase Return cannot be cancelled. Use reverse() to correct a posted return.');
    }
}
