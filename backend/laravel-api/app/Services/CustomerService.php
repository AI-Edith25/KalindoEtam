<?php

namespace App\Services;

use App\Contracts\DocumentNumberGeneratorInterface;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected AuditLogService $auditLogService,
        protected DocumentNumberGeneratorInterface $documentNumberGenerator,
    ) {}

    /** Preview only — see DocumentNumberGeneratorInterface::peek(). The authoritative code is generated fresh in create(). */
    public function peekNextCode(): string
    {
        return $this->documentNumberGenerator->peek('customer');
    }

    /**
     * `search` (customer_code or customer_name) backs SearchableSelect's async mode
     * (searchCustomersLookup in lookupsApi.ts) — callers that omit it get the
     * unfiltered first `perPage` rows, unchanged from before.
     */
    public function list(int $perPage = 200, ?string $search = null): LengthAwarePaginator
    {
        return $this->customerRepository->paginate($perPage, $search);
    }

    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            // Always consume a number — keeps future peekNextCode() suggestions moving forward even
            // when the caller overrides customer_code below, so the next New Customer form doesn't
            // offer a code that was already "spent" (and would just collide) on this one.
            $generated = $this->documentNumberGenerator->generate('customer');
            if (! filled($data['customer_code'] ?? null)) {
                $data['customer_code'] = $generated;
            }
            $customer = $this->customerRepository->create($data);
            $this->auditLogService->record('created', 'customer', "Created customer \"{$customer->customer_name}\".");

            return $customer;
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $customer = $this->customerRepository->update($customer, $data);
            $this->auditLogService->record('updated', 'customer', "Updated customer \"{$customer->customer_name}\".");

            return $customer;
        });
    }

    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $name = $customer->customer_name;
            $this->customerRepository->delete($customer);
            $this->auditLogService->record('deleted', 'customer', "Deleted customer \"{$name}\".");
        });
    }
}
