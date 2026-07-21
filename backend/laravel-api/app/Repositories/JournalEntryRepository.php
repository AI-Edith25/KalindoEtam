<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\JournalEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class JournalEntryRepository extends BaseRepository
{
    protected const EAGER = ['lines.chartOfAccount', 'lines.branch', 'referenceDocument', 'reverses', 'reversedBy', 'creator'];

    public function __construct(JournalEntry $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('posting_date')->paginate($perPage);
    }

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['reference_type'] ?? null, fn ($query, $type) => $query->where('reference_type', $type))
            ->when($filters['account_id'] ?? null, fn ($query, $accountId) => $query->whereHas(
                'lines', fn ($lineQuery) => $lineQuery->where('chart_of_account_id', $accountId)
            ))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('posting_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
            ))
            ->latest('posting_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /** Used by AccountingService::reverseForDocument() — the still-active posted entry for a given source document, if any. */
    public function findActivePostedByReference(string $referenceType, string $referenceId): ?JournalEntry
    {
        return $this->model->query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->whereNull('reversed_by_id')
            ->first();
    }

    /** Used by PeriodManagementService's closing validation — a Draft entry dated inside the period being closed is a real risk (docs/PERIOD_CLOSING_DESIGN.md §3.1). */
    public function countDraftsBetween(string $startDate, string $endDate): int
    {
        return $this->model->query()
            ->where('status', DocumentStatus::DRAFT)
            ->whereDate('posting_date', '>=', $startDate)
            ->whereDate('posting_date', '<=', $endDate)
            ->count();
    }
}
