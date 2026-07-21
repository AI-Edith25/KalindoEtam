<?php

namespace App\Services;

use App\Models\Warehouse;
use App\Repositories\WarehouseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function __construct(
        protected WarehouseRepository $warehouseRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->warehouseRepository->paginate($perPage);
    }

    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            $warehouse = $this->warehouseRepository->create($data);
            $this->auditLogService->record('created', 'warehouse', "Created warehouse \"{$warehouse->name}\".");

            return $warehouse;
        });
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            $warehouse = $this->warehouseRepository->update($warehouse, $data);
            $this->auditLogService->record('updated', 'warehouse', "Updated warehouse \"{$warehouse->name}\".");

            return $warehouse;
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        DB::transaction(function () use ($warehouse) {
            $name = $warehouse->name;
            $this->warehouseRepository->delete($warehouse);
            $this->auditLogService->record('deleted', 'warehouse', "Deleted warehouse \"{$name}\".");
        });
    }
}
