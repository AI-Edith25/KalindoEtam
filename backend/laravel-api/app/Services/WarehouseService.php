<?php

namespace App\Services;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
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
            $this->assertSingleMainWarehouse($data['warehouse_type'] ?? null);

            $warehouse = $this->warehouseRepository->create($data);
            $this->auditLogService->record('created', 'warehouse', "Created warehouse \"{$warehouse->name}\".");

            return $warehouse;
        });
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            $this->assertSingleMainWarehouse($data['warehouse_type'] ?? null, $warehouse->id);

            $warehouse = $this->warehouseRepository->update($warehouse, $data);
            $this->auditLogService->record('updated', 'warehouse', "Updated warehouse \"{$warehouse->name}\".");

            return $warehouse;
        });
    }

    /**
     * "Sync to Main WH" and Sales Order's pricing resolution both need exactly one
     * unambiguous Main warehouse (Warehouse::warehouse_type === MAIN — no separate is_main
     * column). MySQL partial unique indexes aren't clean here, so this is an app-level guard
     * instead, matching this codebase's existing validation style.
     */
    private function assertSingleMainWarehouse(?string $warehouseType, ?string $ignoreId = null): void
    {
        if ($warehouseType !== WarehouseType::MAIN->value) {
            return;
        }

        $exists = Warehouse::query()
            ->where('warehouse_type', WarehouseType::MAIN)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new BusinessException('Only one Main warehouse is allowed.');
        }
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
