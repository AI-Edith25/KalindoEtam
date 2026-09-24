<?php

namespace App\Repositories;

use App\Models\ChartOfAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ChartOfAccountRepository extends BaseRepository
{
    public function __construct(ChartOfAccount $model)
    {
        parent::__construct($model);
    }

    /** account_type/is_active filters — e.g. the Direct Purchase Invoice line's expense-account picker asks for account_type=expense&is_active=1. */
    public function paginate(int $perPage = 100, array $filters = []): LengthAwarePaginator
    {
        return $this->model->query()
            ->when($filters['account_type'] ?? null, fn ($query, $type) => $query->where('account_type', $type))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->paginate($perPage);
    }

    public function findActiveByCode(string $code): ?ChartOfAccount
    {
        return $this->model->query()->where('code', $code)->where('is_active', true)->first();
    }

    /**
     * Every account, unpaginated — used by the General Ledger List, which
     * renders one row per account (dozens at most, never needs pagination)
     * rather than the 15-per-page contract paginate() gives every other
     * caller. Inactive accounts are included: a deactivated account's
     * historical ledger activity must stay visible, only future postings
     * against it are blocked (see docs/GENERAL_LEDGER_DESIGN.md §7).
     */
    public function allOrderedByCode(): \Illuminate\Support\Collection
    {
        return $this->model->query()->orderBy('code')->get();
    }
}
