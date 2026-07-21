<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'posting_date',
        'reference_type',
        'reference_id',
        'description',
        'total_debit',
        'total_credit',
        'reverses_id',
        'reversed_by_id',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'posting_date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'journal';
    }

    /**
     * Scoped to manual entries only (reference_type null — already a real,
     * live-filterable "Manual" category, confirmed docs/APPROVAL_WORKFLOW_DESIGN.md
     * §0). A system-generated entry (Invoice/Credit Note/Debit Note/...) posts
     * atomically inside its source document's own submit() — gating it here
     * would split that one atomic operation into two. See §3.
     *
     * A reversal (reverses_id set) is the same kind of atomic, system-generated
     * write — JournalEntryService::reverse() creates and posts it in one
     * transaction, inheriting the original's reference_type (including null,
     * when reversing a manual entry). It was never typed by a human into a
     * blank form, so it's excluded here for the identical reason a system-
     * generated entry is, not a special case invented on top of that rule.
     */
    public function requiresApproval(): bool
    {
        return $this->reference_type === null && $this->reverses_id === null;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function referenceDocument(): MorphTo
    {
        return $this->morphTo('referenceDocument', 'reference_type', 'reference_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reverses_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by_id');
    }

    /**
     * "Posted" is deliberately Documentable's ordinary SUBMITTED state (see
     * JournalEntryService::post()) — once there, a Journal Entry is
     * immutable by the same assertDraft() guard every other document uses.
     * The only correction path is JournalEntryService::reverse(), which
     * posts a new offsetting entry and leaves this one untouched — never a
     * status flip. Same precedent as Delivery::cancel().
     */
    public function cancel(): static
    {
        throw new BusinessException('Journal Entry cannot be cancelled. Use reverse() to correct a posted entry.');
    }
}
