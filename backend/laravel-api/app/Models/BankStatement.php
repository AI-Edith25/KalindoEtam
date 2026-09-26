<?php

namespace App\Models;

use App\Enums\BankStatementStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An upload run's operational state -- not master data, so no HasAuditTrail/
 * SoftDeletes, same precedent as ImportBatch.
 */
class BankStatement extends Model
{
    use HasUuids;

    protected $fillable = [
        'bank_account_id',
        'format_template',
        'period_start',
        'period_end',
        'original_filename',
        'disk',
        'file_path',
        'status',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'status' => BankStatementStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'bank_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }
}
