<?php

namespace App\Services;

use App\Models\Supplier;
use App\Repositories\SupplierRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function __construct(
        protected SupplierRepository $supplierRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->supplierRepository->paginate($perPage);
    }

    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $supplier = $this->supplierRepository->create($data);
            $this->auditLogService->record('created', 'supplier', "Created supplier \"{$supplier->supplier_name}\".");

            return $supplier;
        });
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier = $this->supplierRepository->update($supplier, $data);
            $this->auditLogService->record('updated', 'supplier', "Updated supplier \"{$supplier->supplier_name}\".");

            return $supplier;
        });
    }

    public function delete(Supplier $supplier): void
    {
        DB::transaction(function () use ($supplier) {
            $name = $supplier->supplier_name;
            $this->supplierRepository->delete($supplier);
            $this->auditLogService->record('deleted', 'supplier', "Deleted supplier \"{$name}\".");
        });
    }
}
