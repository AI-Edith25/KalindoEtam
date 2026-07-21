<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Not Documentable — a period is a boolean gate over a date range, not an
 * authored document (see PeriodStatus). closed_by_id/closed_at/
 * reopened_by_id/reopened_at hold only the latest close/reopen event;
 * timeline() (DocumentTimeline, the same generic mechanism every
 * Documentable model already uses) holds the full multi-cycle history.
 * See docs/PERIOD_CLOSING_DESIGN.md §1/§4.
 */
class AccountingPeriod extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'fiscal_year_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_by_id',
        'closed_at',
        'reopened_by_id',
        'reopened_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => PeriodStatus::class,
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_id');
    }

    public function timeline(): MorphMany
    {
        return $this->morphMany(DocumentTimeline::class, 'subject');
    }
}
