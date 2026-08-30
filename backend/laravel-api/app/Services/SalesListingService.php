<?php

namespace App\Services;

use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Repositories\SalesListingRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class SalesListingService
{
    use BuildsLegacyReportRows;

    protected const TYPE_LABELS = ['invoice' => 'Sales Invoice', 'credit_note' => 'Credit Note'];

    public function __construct(protected SalesListingRepository $salesListingRepository) {}

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->salesListingRepository->paginate($filters, $filters['sort'] ?? 'date', $filters['sort_dir'] ?? 'desc', $perPage);
    }

    public function kpis(array $filters): array
    {
        return $this->salesListingRepository->kpis($filters);
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $rows = $this->salesListingRepository->allListed($filters, $filters['sort'] ?? 'date', $filters['sort_dir'] ?? 'desc');
        $kpis = $this->kpis($filters);

        $headingRow = [
            'DATE', 'DOCUMENT', 'REFERENCE SO', 'REFERENCE DO', 'CUSTOMER CODE', 'CUSTOMER NAME', 'TYPE',
            'AMOUNT EXCL. TAX', 'DISC ADJUSTMENT', 'TAX', 'AMOUNT INCL. TAX', 'PAYMENT STATUS', 'OUTSTANDING AR',
        ];

        $bodyRows = $rows->map(fn ($row) => [
            $this->excelDate(Carbon::parse($row->date)), $row->document_number, $row->reference_so, $row->reference_do,
            $row->customer_code, $row->customer_name, self::TYPE_LABELS[$row->type] ?? $row->type,
            (float) $row->amount, (float) $row->discount, (float) $row->tax, (float) $row->amount_incl_tax,
            $row->payment_status, $row->outstanding_ar !== null ? (float) $row->outstanding_ar : null,
        ])->all();

        $totalsRow = [
            'Grand Total', '', '', '', '', '', '',
            $kpis['net_sales'], null, $kpis['total_tax'], $kpis['gross'], '', null,
        ];

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, 'M');
        }

        return $this->buildXlsxRows(
            title: 'SALES LISTING REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            totalsRow: $totalsRow,
            lastColumn: 'M',
            numberFormatColumns: ['H', 'I', 'J', 'K', 'M'],
            dateColumn: 'A',
            rightAlignColumns: ['H', 'I', 'J', 'K', 'M'],
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('SalesListingReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    protected function excelDate(Carbon $date): float
    {
        return \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-') . ' - ' . ($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
