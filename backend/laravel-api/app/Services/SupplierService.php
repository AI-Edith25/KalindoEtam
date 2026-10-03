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

    /**
     * `search` (supplier_code or supplier_name) backs SearchableSelect's async mode
     * (searchSuppliersLookup in lookupsApi.ts) — callers that omit it get the
     * unfiltered first `perPage` rows, unchanged from before.
     */
    public function list(int $perPage = 200, ?string $search = null): LengthAwarePaginator
    {
        return $this->supplierRepository->paginate($perPage, $search);
    }

    /**
     * `supplier_code` has a real DB-level unique index that, like any plain unique column on a
     * SoftDeletes table, doesn't know about `deleted_at` — StoreSupplierRequest's own
     * whereNull('deleted_at') makes validation accept a code that only collides with a trashed
     * row, but a plain INSERT would still hit the raw SQL unique constraint. Restore and overwrite
     * the trashed row instead (same fix/reasoning as ChartOfAccountService::create()).
     */
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $trashed = Supplier::onlyTrashed()->where('supplier_code', $data['supplier_code'])->first();

            if ($trashed) {
                $trashed->restore();
                $supplier = $this->supplierRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'supplier', "Created supplier \"{$supplier->supplier_name}\" (restored from an archived supplier with the same code).");

                return $supplier;
            }

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
