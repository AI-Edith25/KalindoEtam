<?php

namespace App\Services;

use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Repositories\MarginRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class MarginService
{
    use BuildsLegacyReportRows;

    protected const HEADINGS = [
        'item' => ['ITEM CODE', 'ITEM NAME', 'QTY', 'PENJUALAN', 'HPP', 'PROFIT', 'MARGIN %'],
        'customer' => ['CUSTOMER CODE', 'CUSTOMER NAME', 'JML INVOICE', 'PENJUALAN', 'HPP', 'PROFIT', 'MARGIN %'],
        'invoice' => ['TANGGAL', 'NO INVOICE', 'CUSTOMER', 'SALES PERSON', 'PENJUALAN', 'HPP', 'PROFIT', 'MARGIN %'],
    ];

    public function __construct(protected MarginRepository $marginRepository) {}

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->marginRepository->paginate(
            $filters,
            $filters['group'] ?? 'item',
            $filters['sort'] ?? 'profit',
            $filters['sort_dir'] ?? 'desc',
            $perPage,
        );
    }

    /** @return array{total_sales: float, total_cost: float, total_profit: float, avg_margin_pct: float} */
    public function kpis(array $filters): array
    {
        return $this->marginRepository->kpis($filters);
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $group = $filters['group'] ?? 'item';
        $rows = $this->marginRepository->allGrouped($filters, $group, $filters['sort'] ?? 'profit', $filters['sort_dir'] ?? 'desc');
        $kpis = $this->kpis($filters);
        $headingRow = self::HEADINGS[$group];
        $bodyRows = $this->bodyRows($group, $rows);
        $totalsRow = $this->totalsRow($group, $rows, $kpis);
        $lastColumn = $group === 'invoice' ? 'H' : 'G';
        $numberFormatColumns = $group === 'invoice' ? ['E', 'F', 'G', 'H'] : ['C', 'D', 'E', 'F', 'G'];

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, $lastColumn);
        }

        return $this->buildXlsxRows(
            title: 'MARGIN REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            totalsRow: $totalsRow,
            lastColumn: $lastColumn,
            numberFormatColumns: $numberFormatColumns,
            dateColumn: $group === 'invoice' ? 'A' : null,
            rightAlignColumns: $numberFormatColumns,
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('MarginReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    /** @return array<int, array<int, mixed>> */
    protected function bodyRows(string $group, Collection $rows): array
    {
        return $rows->map(function ($row) use ($group) {
            $amount = (float) $row->amount;
            $cost = (float) $row->cost_amount;
            $profit = (float) $row->profit;
            $margin = (float) $row->margin_pct;

            return match ($group) {
                'customer' => [$row->customer_code, $row->customer_name, (int) $row->invoice_count, $amount, $cost, $profit, $margin],
                'invoice' => [$this->excelDate(Carbon::parse($row->date)), $row->document_number, $row->customer_name, $row->sales_person_name, $amount, $cost, $profit, $margin],
                default => [$row->item_code ?? '—', $row->item_name, (int) $row->qty, $amount, $cost, $profit, $margin],
            };
        })->all();
    }

    /** @return array<int, mixed> Margin % here is computed from the grand totals, never averaged row-by-row. */
    protected function totalsRow(string $group, Collection $rows, array $kpis): array
    {
        $totalMargin = $kpis['total_sales'] != 0.0 ? round($kpis['total_profit'] / $kpis['total_sales'] * 100, 2) : 0.0;

        return match ($group) {
            'customer' => ['Grand Total', '', $rows->sum('invoice_count'), $kpis['total_sales'], $kpis['total_cost'], $kpis['total_profit'], $totalMargin],
            'invoice' => ['Grand Total', '', '', '', $kpis['total_sales'], $kpis['total_cost'], $kpis['total_profit'], $totalMargin],
            default => ['Grand Total', '', $rows->sum('qty'), $kpis['total_sales'], $kpis['total_cost'], $kpis['total_profit'], $totalMargin],
        };
    }

    protected function excelDate(Carbon $date): float
    {
        return Date::PHPToExcel($date);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-').' - '.($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
