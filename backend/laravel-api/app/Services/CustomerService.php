<?php

namespace App\Services;

use App\Models\Customer;
use App\Repositories\CustomerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected AuditLogService $auditLogService,
    ) {}

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
