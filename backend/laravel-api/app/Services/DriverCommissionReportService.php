<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Models\DeliveryItem;
use App\Models\DriverCommissionRate;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * Every row is computed at read time — commission is never stored. A delivery item only appears
 * here when its Delivery has a delivery_status (App\Enums\DeliveryMethod — "Status Pengantaran").
 * Only DELIVERED_WITH_LABOR/DELIVERED_WITHOUT_LABOR get a Driver Commission Rate lookup (and are
 * silently excluded if no matching tariff exists, per spec, never an error); WAREHOUSE_PICKUP
 * (no sopir at all) and PROJECT_DELIVERY (field-negotiated rate, recorded via PV outside this
 * system) still appear for traceability but with a null rate_per_zak/total_commission, which
 * summary() then naturally excludes from every total (null sums as 0).
 */
class DriverCommissionReportService
{
    public function __construct(protected DriverCommissionRateService $driverCommissionRateService) {}

    public function rows(array $filters): Collection
    {
        $query = DeliveryItem::query()
            ->whereHas('delivery', function ($deliveryQuery) use ($filters) {
                $deliveryQuery->whereNotNull('delivery_status');

                if (! empty($filters['date_from'])) {
                    $deliveryQuery->whereDate('delivery_date', '>=', $filters['date_from']);
                }
                if (! empty($filters['date_to'])) {
                    $deliveryQuery->whereDate('delivery_date', '<=', $filters['date_to']);
                }
                if (! empty($filters['fleet_id'])) {
                    $deliveryQuery->where('fleet_id', $filters['fleet_id']);
                }

                // Defaults to the labor-delivered case — same "straight to the commission-payout
                // view" experience the old included-by-default status filter gave.
                $status = $filters['delivery_status'] ?? DeliveryMethod::DELIVERED_WITH_LABOR->value;
                if ($status !== 'all') {
                    $deliveryQuery->where('delivery_status', $status);
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

        $rateEligibleItems = $deliveryItems->filter(
            fn (DeliveryItem $deliveryItem) => $deliveryItem->delivery->delivery_status?->isCommissionRateEligible()
        );

        // Batch-preload every distinct (fleet_id, item_id) pair's rate history once, instead of
        // one resolveRate() query per row — the same "pick latest effective_date <= date" logic
        // just runs in PHP against an already-fetched, already-sorted collection per pair.
        $rateHistoryByPair = DriverCommissionRate::query()
            ->whereIn('fleet_id', $rateEligibleItems->pluck('delivery.fleet_id')->filter()->unique())
            ->whereIn('item_id', $rateEligibleItems->pluck('item_id')->unique())
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (DriverCommissionRate $rate) => $rate->fleet_id.'|'.$rate->item_id);

        $rows = collect();

        foreach ($deliveryItems as $deliveryItem) {
            $delivery = $deliveryItem->delivery;
            $deliveryMethod = $delivery->delivery_status;
            $qty = (float) $deliveryItem->qty;

            $ratePerZak = null;
            $totalCommission = null;

            if ($deliveryMethod?->isCommissionRateEligible()) {
                $pairKey = $delivery->fleet_id.'|'.$deliveryItem->item_id;
                $rate = $rateHistoryByPair->get($pairKey, collect())
                    ->first(fn (DriverCommissionRate $candidate) => $candidate->effective_date->lte($delivery->delivery_date));

                if (! $rate) {
                    continue;
                }

                $ratePerZak = (float) $rate->rate_per_zak;
                $totalCommission = round($qty * $ratePerZak, 2);
            }

            $rows->push((object) [
                'date' => $delivery->delivery_date->toDateString(),
                'so_number' => $delivery->salesOrder?->document_number,
                'do_number' => $delivery->document_number,
                'customer' => $delivery->customer?->customer_name,
                'item_name' => $deliveryItem->item_name,
                'qty' => $qty,
                'plat_number' => $delivery->fleetRecord?->plat_number,
                'driver' => $delivery->driver,
                'delivery_status' => $deliveryMethod?->value,
                'rate_per_zak' => $ratePerZak,
                'total_commission' => $totalCommission,
            ]);
        }

        return $rows->sortBy('date')->values();
    }

    public function summary(Collection $rows): array
    {
        return [
            'total_qty' => (float) $rows->sum('qty'),
            // Rows with a null total_commission (Muat Gudang / Antar Proyek) sum as 0 here — never
            // counted towards the recap total, per spec, even though they still appear above.
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

    /** Backs the report's Jenis Barang filter — only items actually commissioned (master Driver Commission Rates), not the whole Item catalog. Dynamic by design: add a new item there and it appears here automatically, no code change. */
    public function commissionableItems(): Collection
    {
        return Item::query()
            ->whereIn('id', DriverCommissionRate::query()->distinct()->pluck('item_id'))
            ->orderBy('item_name')
            ->get(['id', 'item_code', 'item_name']);
    }
}
