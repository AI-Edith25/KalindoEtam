<?php

namespace App\Models;

use App\Enums\ReportStatementType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Classifies one Chart of Account for one report (docs/PROFIT_LOSS_DESIGN.md
 * §4) — seeded reference data, not a user-authored document, so no audit
 * trail. Deliberately no reverse relation on ChartOfAccount: the accounting
 * master stays unaware this table exists.
 */
class ReportAccountMapping extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'chart_of_account_id',
        'statement_type',
        'section',
        'display_order',
    ];

    protected $casts = [
        'statement_type' => ReportStatementType::class,
    ];

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }
}
