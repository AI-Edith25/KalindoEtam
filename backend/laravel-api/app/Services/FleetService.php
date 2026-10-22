<?php

namespace App\Services;

use App\Models\Fleet;
use App\Repositories\FleetRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FleetService
{
    public function __construct(
        protected FleetRepository $fleetRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->fleetRepository->paginate($perPage);
    }

    public function listActive(): \Illuminate\Database\Eloquent\Collection
    {
        return Fleet::query()->with('location')->where('is_active', true)->orderBy('plat_number')->get();
    }

    /** plat_number has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): Fleet
    {
        return DB::transaction(function () use ($data) {
            $trashed = Fleet::onlyTrashed()->where('plat_number', $data['plat_number'])->first();

            if ($trashed) {
                $trashed->restore();
                $fleet = $this->fleetRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'fleet', "Created fleet \"{$fleet->plat_number}\" (restored from an archived fleet with the same plat number).");

                return $fleet;
            }

            $fleet = $this->fleetRepository->create($data);
            $this->auditLogService->record('created', 'fleet', "Created fleet \"{$fleet->plat_number}\".");

            return $fleet;
        });
    }

    public function update(Fleet $fleet, array $data): Fleet
    {
        return DB::transaction(function () use ($fleet, $data) {
            $fleet = $this->fleetRepository->update($fleet, $data);
            $this->auditLogService->record('updated', 'fleet', "Updated fleet \"{$fleet->plat_number}\".");

            return $fleet;
        });
    }

    public function delete(Fleet $fleet): void
    {
        DB::transaction(function () use ($fleet) {
            $platNumber = $fleet->plat_number;
            $this->fleetRepository->delete($fleet);
            $this->auditLogService->record('deleted', 'fleet', "Deleted fleet \"{$platNumber}\".");
        });
    }
}
