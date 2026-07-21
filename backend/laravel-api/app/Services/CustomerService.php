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

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->customerRepository->paginate($perPage);
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
