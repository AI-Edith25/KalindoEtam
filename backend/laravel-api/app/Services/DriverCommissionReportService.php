<?php

namespace App\Services;

use App\Models\DeliveryItem;
use App\Models\DriverCommissionRate;
use Illuminate\Support\Collection;

/**
 * Every row is computed at read time — commission is never stored. A delivery item only
 * appears here when its Delivery has a fleet_id AND DriverCommissionRateService::resolveRate()
 * finds a matching tariff; anything else is silently excluded (per spec, never an error).
 */
class DriverCommissionReportService
{
    public function __construct(protected DriverCommissionRateService $driverCommissionRateService) {}

    public function rows(array $filters): Collection
    {
        $query = DeliveryItem::query()
            ->whereHas('delivery', function ($deliveryQuery) use ($filters) {
                $deliveryQuery->whereNotNull('fleet_id');

                if (! empty($filters['date_from'])) {
                    $deliveryQuery->whereDate('delivery_date', '>=', $filters['date_from']);
                }
                if (! empty($filters['date_to'])) {
                    $deliveryQuery->whereDate('delivery_date', '<=', $filters['date_to']);
                }
                if (! empty($filters['driver'])) {
                    $deliveryQuery->where('driver', $filters['driver']);
                }
                if (! empty($filters['fleet_id'])) {
                    $deliveryQuery->where('fleet_id', $filters['fleet_id']);
                }

                $status = $filters['status'] ?? 'included';
                if ($status === 'included') {
                    $deliveryQuery->where('include_in_commission_recap', true);
                } elseif ($status === 'excluded') {
                    $deliveryQuery->where('include_in_commission_recap', false);
                }
            })
            ->with(['delivery.fleetRecord', 'delivery.customer', 'delivery.salesOrder', 'item']);

        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        $deliveryItems = $query->get();

        if ($deliveryItems->isEmpty()) {
            return collect();
        }

        // Batch-preload every distinct (fleet_id, item_id) pair's rate history once, instead of
        // one resolveRate() query per row — the same "pick latest effective_date <= date" logic
        // just runs in PHP against an already-fetched, already-sorted collection per pair.
        $rateHistoryByPair = DriverCommissionRate::query()
            ->whereIn('fleet_id', $deliveryItems->pluck('delivery.fleet_id')->unique())
            ->whereIn('item_id', $deliveryItems->pluck('item_id')->unique())
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (DriverCommissionRate $rate) => $rate->fleet_id.'|'.$rate->item_id);

        $rows = collect();

        foreach ($deliveryItems as $deliveryItem) {
            $delivery = $deliveryItem->delivery;
            $pairKey = $delivery->fleet_id.'|'.$deliveryItem->item_id;

            $rate = $rateHistoryByPair->get($pairKey, collect())
                ->first(fn (DriverCommissionRate $candidate) => $candidate->effective_date->lte($delivery->delivery_date));

            if (! $rate) {
                continue;
            }

            $qty = (float) $deliveryItem->qty;
            $ratePerZak = (float) $rate->rate_per_zak;

            $rows->push((object) [
                'date' => $delivery->delivery_date->toDateString(),
                'so_number' => $delivery->salesOrder?->document_number,
                'do_number' => $delivery->document_number,
                'customer' => $delivery->customer?->customer_name,
                'item_name' => $deliveryItem->item_name,
                'qty' => $qty,
                'plat_number' => $delivery->fleetRecord?->plat_number,
                'driver' => $delivery->driver,
                'rate_per_zak' => $ratePerZak,
                'total_commission' => round($qty * $ratePerZak, 2),
            ]);
        }

        return $rows->sortBy('date')->values();
    }

    public function summary(Collection $rows): array
    {
        return [
            'total_qty' => (float) $rows->sum('qty'),
            'total_commission' => (float) $rows->sum('total_commission'),
            'by_driver' => $rows->groupBy('driver')
                ->map(fn (Collection $group, ?string $driver) => [
                    'driver' => $driver,
                    'total_qty' => (float) $group->sum('qty'),
                    'total_commission' => (float) $group->sum('total_commission'),
                ])
                ->values(),
        ];
    }

    /** Backs the report's Sopir filter dropdown — every driver name ever used on a fleet-linked Delivery, regardless of the current filters. */
    public function distinctDrivers(): Collection
    {
        return \App\Models\Delivery::query()
            ->whereNotNull('fleet_id')
            ->whereNotNull('driver')
            ->distinct()
            ->orderBy('driver')
            ->pluck('driver');
    }
}
