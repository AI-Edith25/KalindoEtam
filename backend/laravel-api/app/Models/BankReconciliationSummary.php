<?php

namespace App\Models;

use App\Enums\BankReconciliationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationSummary extends Model
{
    use HasUuids;

    protected $fillable = [
        'bank_account_id',
        'date',
        'system_debit_total',
        'system_credit_total',
        'statement_debit_total',
        'statement_credit_total',
        'variance_debit',
        'variance_credit',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'date' => 'date',
        'system_debit_total' => 'decimal:2',
        'system_credit_total' => 'decimal:2',
        'statement_debit_total' => 'decimal:2',
        'statement_credit_total' => 'decimal:2',
        'variance_debit' => 'decimal:2',
        'variance_credit' => 'decimal:2',
        'status' => BankReconciliationStatus::class,
        'generated_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'bank_account_id');
    }
}
