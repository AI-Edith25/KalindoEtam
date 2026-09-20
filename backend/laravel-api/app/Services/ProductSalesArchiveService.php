<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ProductSalesSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads product_sales_snapshot_lines across every stacked period, aggregating at query time
 * (never trusting the file's own item-row subtotal, same principle as Perincian Piutang) --
 * feeds the Product Sales tab, grouped per item or per item group depending on the existing
 * "Ringkas per Group" toggle. The customer-code/name kept on every raw line (see
 * ProductSalesArchiveImportService) also makes the item's existing customer drill-down dialog
 * work for free in archive mode.
 */
class ProductSalesArchiveService
{
    public function hasSnapshot(): bool
    {
        return ProductSalesSnapshot::query()->exists();
    }

    /** @return ?array{period_start: string, period_end: string} */
    public function combinedPeriod(): ?array
    {
        $agg = ProductSalesSnapshot::query()->selectRaw('MIN(period_start) as start, MAX(period_end) as end')->first();

        return $agg && $agg->start ? ['period_start' => $agg->start, 'period_end' => $agg->end] : null;
    }

    /** @return array{data: array, total: int, kpis: array} */
    public function productSales(array $filters, string $group, int $page, int $perPage): array
    {
        if (! empty($filters['sales_person_id']) || ! empty($filters['branch_id']) || ! empty($filters['status'])) {
            return ['data' => [], 'total' => 0, 'kpis' => $this->emptyKpis()];
        }

        $lines = $this->baseLines($filters);
        if ($lines === null) {
            return ['data' => [], 'total' => 0, 'kpis' => $this->emptyKpis()];
        }

        $rows = $group === 'item_group' ? $this->groupByItemGroup($lines) : $this->groupByItem($lines);
        $rows = $rows->sortByDesc('amount')->values();

        $totalRevenue = round((float) $rows->sum('amount'), 2);
        $kpis = [
            'total_qty' => round((float) $rows->sum('qty'), 2),
            'total_revenue' => $totalRevenue,
            'total_tax' => round((float) $rows->sum('tax_amount'), 2),
            'total_incl_tax' => round((float) $rows->sum('amount_incl_tax'), 2),
            'sku_count' => $lines->pluck('item_code')->unique()->count(),
            'top_item_name' => $rows->first()['item_name'] ?? null,
            'top_item_amount' => $rows->first()['amount'] ?? 0.0,
        ];

        return [
            'data' => $rows->forPage($page, $perPage)->values()->all(),
            'total' => $rows->count(),
            'kpis' => $kpis,
        ];
    }

    /** @return array<int, array{customer_id: string, customer_code: string, customer_name: string, qty: float, amount: float}> */
    public function customersForItem(string $itemCode, array $filters): array
    {
        $lines = $this->baseLines($filters, itemCodeOverride: $itemCode);
        if ($lines === null) {
            return [];
        }

        return $lines->filter(fn ($line) => $line->customer_code !== null)
            ->groupBy('customer_code')
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'customer_id' => $first->customer_code,
                    'customer_code' => $first->customer_code,
                    'customer_name' => $first->customer_name,
                    'qty' => round((float) $group->sum('qty'), 2),
                    'amount' => round((float) $group->sum('amount_excl_tax'), 2),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function baseLines(array $filters, ?string $itemCodeOverride = null): ?Collection
    {
        $itemCode = $itemCodeOverride;
        if ($itemCode === null && ! empty($filters['item_id'])) {
            $itemCode = Item::query()->find($filters['item_id'])?->item_code;
            if ($itemCode === null) {
                return null;
            }
        }

        $itemGroupName = null;
        if (! empty($filters['item_group_id'])) {
            $itemGroupName = ItemGroup::query()->find($filters['item_group_id'])?->name;
            if ($itemGroupName === null) {
                return null;
            }
        }

        $customerCode = null;
        if (! empty($filters['customer_id'])) {
            $customerCode = Customer::query()->find($filters['customer_id'])?->customer_code;
            if ($customerCode === null) {
                return null;
            }
        }

        return DB::table('product_sales_snapshot_lines')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('txn_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('txn_date', '<=', $d))
            ->when($itemCode, fn ($q, $c) => $q->where('item_code', $c))
            ->when($itemGroupName, fn ($q, $g) => $q->where('item_group', $g))
            ->when($customerCode, fn ($q, $c) => $q->where('customer_code', $c))
            ->get();
    }

    private function groupByItem(Collection $lines): Collection
    {
        return $lines->groupBy('item_code')->map(function (Collection $group) {
            $first = $group->first();

            return [
                'id' => $first->item_code,
                'is_group' => false,
                'item_code' => $first->item_code,
                'item_name' => $first->item_description,
                'item_group_name' => $first->item_group ?? 'Unassigned',
                'uom_name' => null,
                'sku_count' => null,
                'qty' => round((float) $group->sum('qty'), 2),
                'amount' => round((float) $group->sum('amount_excl_tax'), 2),
                'tax_amount' => round((float) $group->sum('tax'), 2),
                'amount_incl_tax' => round((float) $group->sum('amount_incl_tax'), 2),
            ];
        });
    }

    private function groupByItemGroup(Collection $lines): Collection
    {
        return $lines->groupBy(fn ($line) => $line->item_group ?? 'Unassigned')->map(function (Collection $group, string $groupName) {
            return [
                'id' => $groupName,
                'is_group' => true,
                'item_code' => null,
                'item_name' => $groupName,
                'item_group_name' => null,
                'uom_name' => null,
                'sku_count' => $group->pluck('item_code')->unique()->count(),
                'qty' => round((float) $group->sum('qty'), 2),
                'amount' => round((float) $group->sum('amount_excl_tax'), 2),
                'tax_amount' => round((float) $group->sum('tax'), 2),
                'amount_incl_tax' => round((float) $group->sum('amount_incl_tax'), 2),
            ];
        });
    }

    private function emptyKpis(): array
    {
        return [
            'total_qty' => 0.0, 'total_revenue' => 0.0, 'total_tax' => 0.0, 'total_incl_tax' => 0.0,
            'sku_count' => 0, 'top_item_name' => null, 'top_item_amount' => 0.0,
        ];
    }
}
