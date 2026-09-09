<?php

namespace App\Services;

use App\Repositories\FifoLayerConsumptionRepository;
use App\Repositories\FifoLayerRepository;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Period stock valuation — Opening/Qty In/Qty Out/Closing per (item, warehouse), replayed from
 * FifoLayer + FifoLayerConsumption's immutable audit trail at two date cutoffs. Deliberately not
 * built on StockLedgerService::attachCostInfo()'s balance_value (see its docblock): that prices
 * every row at *today's* average cost, which is fine for a live ledger view but would make
 * Opening/Closing wrong for any period that isn't "up to now." This replays each layer's own
 * qty_in/unit_cost and its consumption history up to each cutoff instead, so Opening + Qty In -
 * Qty Out always reconciles exactly to Closing.
 */
class InventoryValuationService
{
    public function __construct(
        protected FifoLayerRepository $fifoLayerRepository,
        protected FifoLayerConsumptionRepository $fifoLayerConsumptionRepository,
    ) {}

    public function report(array $filters, int $page, int $perPage = 15): LengthAwarePaginator
    {
        $rows = $this->rows($filters);

        return new Paginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    public function summary(array $filters): array
    {
        $rows = $this->rows($filters);

        return [
            'closing_value' => round((float) $rows->sum('closing_value'), 2),
            'item_count' => $rows->pluck('item_id')->unique()->count(),
        ];
    }

    /** Every (item, warehouse) row, unpaginated — the export's source, same filters and shape as the screen. */
    public function exportRows(array $filters): Collection
    {
        return $this->rows($filters);
    }

    /** @return Collection<int, object> one row per (item, warehouse), sorted by item code */
    private function rows(array $filters): Collection
    {
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];

        $layers = $this->fifoLayerRepository->layersForValuation($filters, $dateTo);
        $sums = $this->fifoLayerConsumptionRepository->boundedSumsForLayers($layers->pluck('id')->all(), $dateFrom, $dateTo);
        $periodStart = Carbon::parse($dateFrom);

        return $layers
            ->map(function ($layer) use ($sums, $periodStart) {
                $sum = $sums->get($layer->id);
                $beforeFrom = (float) ($sum->before_from ?? 0);
                $throughTo = (float) ($sum->through_to ?? 0);
                $qtyIn = (float) $layer->qty_in;
                $unitCost = (float) $layer->unit_cost;
                $receivedBeforePeriod = $layer->received_date->lt($periodStart);

                $openingQty = $receivedBeforePeriod ? $qtyIn - $beforeFrom : 0.0;
                $closingQty = $qtyIn - $throughTo;
                $qtyInPeriod = $receivedBeforePeriod ? 0.0 : $qtyIn;
                $qtyOutPeriod = $throughTo - $beforeFrom;

                return (object) [
                    'item_id' => $layer->item_id,
                    'warehouse_id' => $layer->warehouse_id,
                    'item_code' => $layer->item_code,
                    'item_name' => $layer->item_name,
                    'warehouse_name' => $layer->warehouse_name,
                    'opening_qty' => $openingQty,
                    'opening_value' => $openingQty * $unitCost,
                    'qty_in' => $qtyInPeriod,
                    'value_in' => $qtyInPeriod * $unitCost,
                    'qty_out' => $qtyOutPeriod,
                    'value_out' => $qtyOutPeriod * $unitCost,
                    'closing_qty' => $closingQty,
                    'closing_value' => $closingQty * $unitCost,
                ];
            })
            ->groupBy(fn ($row) => "{$row->item_id}|{$row->warehouse_id}")
            ->map(fn (Collection $group) => $this->summarizeGroup($group))
            ->values()
            ->sortBy('item_code')
            ->values();
    }

    private function summarizeGroup(Collection $rows): object
    {
        $first = $rows->first();
        $closingQty = (float) $rows->sum('closing_qty');
        $closingValue = round((float) $rows->sum('closing_value'), 2);

        return (object) [
            'item_id' => $first->item_id,
            'warehouse_id' => $first->warehouse_id,
            'item_code' => $first->item_code,
            'item_name' => $first->item_name,
            'warehouse_name' => $first->warehouse_name,
            'opening_qty' => (float) $rows->sum('opening_qty'),
            'opening_value' => round((float) $rows->sum('opening_value'), 2),
            'qty_in' => (float) $rows->sum('qty_in'),
            'value_in' => round((float) $rows->sum('value_in'), 2),
            'qty_out' => (float) $rows->sum('qty_out'),
            'value_out' => round((float) $rows->sum('value_out'), 2),
            'closing_qty' => $closingQty,
            'closing_value' => $closingValue,
            'unit_cost' => $closingQty > 0 ? round($closingValue / $closingQty, 2) : 0.0,
        ];
    }
}
