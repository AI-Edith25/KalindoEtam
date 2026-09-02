<?php

namespace App\Services;

use App\Models\PriceZone;
use App\Repositories\PriceZoneRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PriceZoneService
{
    public function __construct(
        protected PriceZoneRepository $priceZoneRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->priceZoneRepository->paginate($perPage);
    }

    public function create(array $data): PriceZone
    {
        return DB::transaction(function () use ($data) {
            $priceZone = $this->priceZoneRepository->create($data);
            $this->auditLogService->record('created', 'price_zone', "Created price zone \"{$priceZone->name}\".");

            return $priceZone;
        });
    }

    public function update(PriceZone $priceZone, array $data): PriceZone
    {
        return DB::transaction(function () use ($priceZone, $data) {
            $priceZone = $this->priceZoneRepository->update($priceZone, $data);
            $this->auditLogService->record('updated', 'price_zone', "Updated price zone \"{$priceZone->name}\".");

            return $priceZone;
        });
    }

    public function delete(PriceZone $priceZone): void
    {
        DB::transaction(function () use ($priceZone) {
            $name = $priceZone->name;
            $this->priceZoneRepository->delete($priceZone);
            $this->auditLogService->record('deleted', 'price_zone', "Deleted price zone \"{$name}\".");
        });
    }
}
