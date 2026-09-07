<?php

namespace App\Services;

use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Repositories\PurchaseBySupplierRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class PurchaseBySupplierService
{
    use BuildsLegacyReportRows;

    public function __construct(protected PurchaseBySupplierRepository $purchaseBySupplierRepository) {}

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->purchaseBySupplierRepository->paginate($filters, $filters['sort'] ?? 'amount', $filters['sort_dir'] ?? 'desc', $perPage);
    }

    /** @return array{total_purchases: float, active_supplier_count: int, top_supplier_name: ?string, top_supplier_amount: float} */
    public function kpis(array $filters): array
    {
        return $this->purchaseBySupplierRepository->kpis($filters);
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $rows = $this->purchaseBySupplierRepository->allGrouped($filters, $filters['sort'] ?? 'amount', $filters['sort_dir'] ?? 'desc');
        $kpis = $this->kpis($filters);
        $totalPurchases = $kpis['total_purchases'] ?: 1; // avoid div-by-zero when the filtered set is empty

        $headingRow = ['SUPPLIER CODE', 'SUPPLIER NAME', 'JML PENERIMAAN', 'TOTAL QTY', 'NILAI PEMBELIAN', '% DARI TOTAL'];

        $bodyRows = $rows->map(function ($row) use ($totalPurchases) {
            $amount = (float) $row->amount;

            return [$row->supplier_code, $row->supplier_name, (int) $row->receipt_count, (float) $row->qty, $amount, round($amount / $totalPurchases * 100, 2)];
        })->all();

        $totalsRow = ['Grand Total', '', $rows->sum('receipt_count'), $rows->sum('qty'), $kpis['total_purchases'], 100.0];

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, 'F');
        }

        return $this->buildXlsxRows(
            title: 'PURCHASE BY SUPPLIER REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            totalsRow: $totalsRow,
            lastColumn: 'F',
            numberFormatColumns: ['D', 'E', 'F'],
            rightAlignColumns: ['C', 'D', 'E', 'F'],
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('PurchaseBySupplierReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-').' - '.($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
