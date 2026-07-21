<?php

namespace App\Services;

use App\Models\UnitOfMeasurement;
use App\Repositories\UomRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UomService
{
    public function __construct(
        protected UomRepository $uomRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->uomRepository->paginate($perPage);
    }

    public function create(array $data): UnitOfMeasurement
    {
        return DB::transaction(function () use ($data) {
            $uom = $this->uomRepository->create($data);
            $this->auditLogService->record('created', 'uom', "Created UOM \"{$uom->name}\".");

            return $uom;
        });
    }

    public function update(UnitOfMeasurement $uom, array $data): UnitOfMeasurement
    {
        return DB::transaction(function () use ($uom, $data) {
            $uom = $this->uomRepository->update($uom, $data);
            $this->auditLogService->record('updated', 'uom', "Updated UOM \"{$uom->name}\".");

            return $uom;
        });
    }

    public function delete(UnitOfMeasurement $uom): void
    {
        DB::transaction(function () use ($uom) {
            $name = $uom->name;
            $this->uomRepository->delete($uom);
            $this->auditLogService->record('deleted', 'uom', "Deleted UOM \"{$name}\".");
        });
    }
}
