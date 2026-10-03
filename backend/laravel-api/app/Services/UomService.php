<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\ItemUom;
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

    /** `name` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): UnitOfMeasurement
    {
        return DB::transaction(function () use ($data) {
            $trashed = UnitOfMeasurement::onlyTrashed()->where('name', $data['name'])->first();

            if ($trashed) {
                $trashed->restore();
                $uom = $this->uomRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'uom', "Created UOM \"{$uom->name}\" (restored from an archived UOM with the same name).");

                return $uom;
            }

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
            if ($uom->items()->exists() || ItemUom::query()->where('uom_id', $uom->id)->exists()) {
                throw new BusinessException("Cannot delete UOM \"{$uom->name}\": it is used by one or more items.");
            }

            $name = $uom->name;
            $this->uomRepository->delete($uom);
            $this->auditLogService->record('deleted', 'uom', "Deleted UOM \"{$name}\".");
        });
    }
}
