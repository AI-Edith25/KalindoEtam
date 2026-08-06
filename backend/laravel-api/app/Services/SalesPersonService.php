<?php

namespace App\Services;

use App\Models\SalesPerson;
use App\Repositories\SalesPersonRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesPersonService
{
    public function __construct(
        protected SalesPersonRepository $salesPersonRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->salesPersonRepository->paginate($perPage);
    }

    public function create(array $data): SalesPerson
    {
        return DB::transaction(function () use ($data) {
            $salesPerson = $this->salesPersonRepository->create($data);
            $this->auditLogService->record('created', 'sales_person', "Created sales person \"{$salesPerson->name}\".");

            return $salesPerson;
        });
    }

    public function update(SalesPerson $salesPerson, array $data): SalesPerson
    {
        return DB::transaction(function () use ($salesPerson, $data) {
            $salesPerson = $this->salesPersonRepository->update($salesPerson, $data);
            $this->auditLogService->record('updated', 'sales_person', "Updated sales person \"{$salesPerson->name}\".");

            return $salesPerson;
        });
    }

    public function delete(SalesPerson $salesPerson): void
    {
        DB::transaction(function () use ($salesPerson) {
            $name = $salesPerson->name;
            $this->salesPersonRepository->delete($salesPerson);
            $this->auditLogService->record('deleted', 'sales_person', "Deleted sales person \"{$name}\".");
        });
    }
}
