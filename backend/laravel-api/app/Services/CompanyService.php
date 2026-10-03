<?php

namespace App\Services;

use App\Models\Company;
use App\Repositories\CompanyRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function __construct(
        protected CompanyRepository $companyRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->companyRepository->paginate($perPage);
    }

    /** `code` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): Company
    {
        return DB::transaction(function () use ($data) {
            $trashed = Company::onlyTrashed()->where('code', $data['code'])->first();

            if ($trashed) {
                $trashed->restore();
                $company = $this->companyRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'company', "Created company \"{$company->name}\" (restored from an archived company with the same code).");

                return $company;
            }

            $company = $this->companyRepository->create($data);
            $this->auditLogService->record('created', 'company', "Created company \"{$company->name}\".");

            return $company;
        });
    }

    public function update(Company $company, array $data): Company
    {
        return DB::transaction(function () use ($company, $data) {
            $company = $this->companyRepository->update($company, $data);
            $this->auditLogService->record('updated', 'company', "Updated company \"{$company->name}\".");

            return $company;
        });
    }

    public function delete(Company $company): void
    {
        DB::transaction(function () use ($company) {
            $name = $company->name;
            $this->companyRepository->delete($company);
            $this->auditLogService->record('deleted', 'company', "Deleted company \"{$name}\".");
        });
    }
}
