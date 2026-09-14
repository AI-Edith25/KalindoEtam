<?php

namespace App\Services;

use App\Contracts\DocumentNumberGeneratorInterface;
use App\Models\SalesPerson;
use App\Repositories\SalesPersonRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesPersonService
{
    public function __construct(
        protected SalesPersonRepository $salesPersonRepository,
        protected AuditLogService $auditLogService,
        protected DocumentNumberGeneratorInterface $documentNumberGenerator,
    ) {}

    /** Preview only — see DocumentNumberGeneratorInterface::peek(). The authoritative code is generated fresh in create(). */
    public function peekNextCode(): string
    {
        return $this->documentNumberGenerator->peek('sales_person');
    }

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->salesPersonRepository->paginate($perPage);
    }

    public function create(array $data): SalesPerson
    {
        return DB::transaction(function () use ($data) {
            // Always consume a number — keeps future peekNextCode() suggestions moving forward even
            // when the caller overrides code below, so the next New Sales Person form doesn't offer
            // a code that was already "spent" (and would just collide) on this one.
            $generated = $this->documentNumberGenerator->generate('sales_person');
            if (! filled($data['code'] ?? null)) {
                $data['code'] = $generated;
            }
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
