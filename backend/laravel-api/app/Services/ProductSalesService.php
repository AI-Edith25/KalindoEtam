<?php

namespace App\Services;

use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Repositories\ProductSalesRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ProductSalesService
{
    use BuildsLegacyReportRows;

    public function __construct(protected ProductSalesRepository $productSalesRepository) {}

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->productSalesRepository->paginate(
            $filters,
            $filters['group'] ?? 'item',
            $filters['sort'] ?? 'amount',
            $filters['sort_dir'] ?? 'desc',
            $perPage,
        );
    }

    public function kpis(array $filters): array
    {
        return $this->productSalesRepository->kpis($filters);
    }

    public function customersForItem(string $itemId, array $filters): \Illuminate\Support\Collection
    {
        return $this->productSalesRepository->customersForItem($itemId, $filters);
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $group = $filters['group'] ?? 'item';
        $rows = $this->productSalesRepository->allGrouped(
            $filters,
            $group,
            $filters['sort'] ?? 'amount',
            $filters['sort_dir'] ?? 'desc',
        );
        $kpis = $this->kpis($filters);
        $totalRevenue = $kpis['total_revenue'] ?: 1; // avoid div-by-zero when the filtered set is empty

        $isGrouped = $group === 'item_group';
        $headingRow = $isGrouped
            ? ['ITEM GROUP', 'SKU COUNT', 'QTY', 'AMOUNT EXCL. TAX', 'TAX', 'AMOUNT INCL. TAX', '% OF REVENUE']
            : ['ITEM CODE', 'DESCRIPTION', 'ITEM GROUP', 'UOM', 'QTY', 'AMOUNT EXCL. TAX', 'DISC/ADJUSTMENT', 'TAX', 'AMOUNT INCL. TAX', '% OF REVENUE'];

        $bodyRows = $rows->map(function ($row) use ($isGrouped, $totalRevenue) {
            $amount = (float) $row->amount;
            $tax = (float) $row->tax_amount;
            $pct = round($amount / $totalRevenue * 100, 2);

            return $isGrouped
                ? [$row->group_name ?? 'Unassigned', (int) $row->sku_count, (int) $row->qty, $amount, $tax, $amount + $tax, $pct]
                : [$row->item_code, $row->item_name, $row->group_name ?? 'Unassigned', $row->uom_name ?? '', (int) $row->qty, $amount, 0.0, $tax, $amount + $tax, $pct];
        })->all();

        $totalsRow = $isGrouped
            ? ['Grand Total', $rows->sum('sku_count'), $rows->sum('qty'), $kpis['total_revenue'], $kpis['total_tax'], $kpis['total_incl_tax'], 100.0]
            : ['Grand Total', '', '', '', $rows->sum('qty'), $kpis['total_revenue'], 0.0, $kpis['total_tax'], $kpis['total_incl_tax'], 100.0];

        $lastColumn = $isGrouped ? 'G' : 'J';
        $numberFormatColumns = $isGrouped ? ['C', 'D', 'E', 'F', 'G'] : ['E', 'F', 'G', 'H', 'I', 'J'];
        $rightAlignColumns = $numberFormatColumns;

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, $lastColumn);
        }

        return $this->buildXlsxRows(
            title: 'PRODUCT SALES REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            totalsRow: $totalsRow,
            lastColumn: $lastColumn,
            numberFormatColumns: $numberFormatColumns,
            rightAlignColumns: $rightAlignColumns,
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('ProductSalesReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-') . ' - ' . ($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
