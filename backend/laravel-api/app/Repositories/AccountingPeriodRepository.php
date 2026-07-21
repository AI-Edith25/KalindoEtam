<?php

namespace App\Repositories;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AccountingPeriodRepository extends BaseRepository
{
    protected const EAGER = ['fiscalYear', 'closedBy', 'reopenedBy'];

    public function __construct(AccountingPeriod $model)
    {
        parent::__construct($model);
    }

    /**
     * The period covering a given date, if one exists — the single lookup
     * PeriodLockService::assertOpen() is built on. Returns null when no
     * period record exists at all, which PeriodLockService treats as "not
     * blocked" (fail-open, docs/PERIOD_CLOSING_DESIGN.md §1).
     */
    public function containing(string $date, ?string $companyId = null): ?AccountingPeriod
    {
        return $this->scopeToCompany($this->model->query(), $companyId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['fiscal_year_id'] ?? null, fn ($query, $id) => $query->where('fiscal_year_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('start_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): AccountingPeriod
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /** The period immediately before this one (by start_date), same company — the §3.4 sequential-close check. */
    public function preceding(AccountingPeriod $period, ?string $companyId = null): ?AccountingPeriod
    {
        return $this->scopeToCompany($this->model->query(), $companyId)
            ->where('start_date', '<', $period->start_date)
            ->orderByDesc('start_date')
            ->first();
    }

    /** Whether any later period (by start_date), same company, is still Closed — the §4 reverse-sequential reopen check. */
    public function hasLaterClosedPeriod(AccountingPeriod $period, ?string $companyId = null): bool
    {
        return $this->scopeToCompany($this->model->query(), $companyId)
            ->where('start_date', '>', $period->start_date)
            ->where('status', PeriodStatus::CLOSED->value)
            ->exists();
    }

    protected function scopeToCompany(Builder $query, ?string $companyId): Builder
    {
        if ($companyId) {
            $query->whereHas('fiscalYear', fn ($q) => $q->where('company_id', $companyId));
        }

        return $query;
    }
}
