<?php

namespace App\Models;

use App\Enums\BankStatementLineMatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BankStatementLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'bank_statement_id',
        'transaction_date',
        'description',
        'debit_amount',
        'credit_amount',
        'running_balance',
        'matched_document_type',
        'matched_document_id',
        'match_status',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'match_status' => BankStatementLineMatchStatus::class,
    ];

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class);
    }

    /**
     * Always a PaymentEntry or ReceiptEntry document -- same nullable-morph
     * shape as JournalEntry::referenceDocument().
     */
    public function matchedDocument(): MorphTo
    {
        return $this->morphTo('matchedDocument', 'matched_document_type', 'matched_document_id');
    }
}
