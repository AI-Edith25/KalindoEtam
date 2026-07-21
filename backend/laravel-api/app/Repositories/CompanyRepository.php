<?php

namespace App\Repositories;

use App\Models\Company;

class CompanyRepository extends BaseRepository
{
    public function __construct(Company $model)
    {
        parent::__construct($model);
    }

    /**
     * The given company if an id is provided, otherwise the first company —
     * the same "selected company, or fall back to the first one" rule the
     * frontend's resolveFiscalYearStart() already applies client-side. See
     * docs/BALANCE_SHEET_DESIGN.md §5.
     */
    public function defaultOrById(?string $companyId): ?Company
    {
        /** @var Company|null */
        return $companyId
            ? $this->model->query()->find($companyId)
            : $this->model->query()->first();
    }
}
