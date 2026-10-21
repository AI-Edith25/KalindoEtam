<?php

namespace App\Services;

use App\Models\DriverCommissionRate;
use App\Repositories\DriverCommissionRateRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DriverCommissionRateService
{
    public function __construct(
        protected DriverCommissionRateRepository $driverCommissionRateRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function listForFleet(string $fleetId): Collection
    {
        return DriverCommissionRate::query()
            ->where('fleet_id', $fleetId)
            ->with('item')
            ->orderBy('item_id')
            ->orderByDesc('effective_date')
            ->get();
    }

    /**
     * Rates are append-only history — adding a new tariff for an existing fleet+item pair is
     * always an insert, never an update/replace (see migration comment). This is the single
     * place a new rate row is written.
     */
    public function create(array $data): DriverCommissionRate
    {
        return DB::transaction(function () use ($data) {
            $rate = $this->driverCommissionRateRepository->create($data);
            $this->auditLogService->record('created', 'driver_commission_rate', "Added commission rate {$rate->rate_per_zak}/zak for fleet {$rate->fleet_id}, item {$rate->item_id}, effective {$rate->effective_date->toDateString()}.");

            return $rate;
        });
    }

    public function delete(DriverCommissionRate $rate): void
    {
        DB::transaction(function () use ($rate) {
            $this->driverCommissionRateRepository->delete($rate);
            $this->auditLogService->record('deleted', 'driver_commission_rate', "Deleted commission rate entry for fleet {$rate->fleet_id}, item {$rate->item_id}, effective {$rate->effective_date->toDateString()}.");
        });
    }

    /**
     * The single choke point every caller (the report, and any future one) resolves a
     * commission rate through: the latest rate whose effective_date is on or before the
     * delivery date. Returns null — never throws — when the fleet/item isn't registered or no
     * rate has taken effect yet, since an unmatched delivery is simply excluded, not an error.
     */
    public function resolveRate(string $fleetId, string $itemId, CarbonInterface $deliveryDate): ?DriverCommissionRate
    {
        return DriverCommissionRate::query()
            ->where('fleet_id', $fleetId)
            ->where('item_id', $itemId)
            ->whereDate('effective_date', '<=', $deliveryDate->toDateString())
            ->orderByDesc('effective_date')
            ->first();
    }
}
