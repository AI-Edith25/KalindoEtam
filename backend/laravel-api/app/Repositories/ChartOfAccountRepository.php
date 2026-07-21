<?php

namespace App\Repositories;

use App\Models\ChartOfAccount;

class ChartOfAccountRepository extends BaseRepository
{
    public function __construct(ChartOfAccount $model)
    {
        parent::__construct($model);
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
