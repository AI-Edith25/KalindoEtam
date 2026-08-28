<?php

namespace App\Exports\Concerns;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Row/meta assembly for the "Summary" export variant of Sales Orders,
 * Deliveries, Credit Notes and Debit Notes — title/date-range/company+
 * timestamp/heading/body/TAX SUMMARY/Printed By, modeled directly on 4 real
 * legacy-system reference files (SalesOrderListing_Summary.xlsx,
 * DeliveryOrderListing_Summary.xlsx, rptCreditNoteToCustomerListing_Summary.xlsx,
 * rptDebitNoteToCustomerListing_Summary.xlsx), Currency column omitted per
 * the ticket.
 *
 * Deliberately separate from SalesReportService::wrapReport() (the older
 * Sales -> Invoices "Laporan Penjualan" export) — that method is hardcoded to
 * Invoice and pre-formats money as Indonesian-style text, whereas these 4
 * reference files store real numeric cells with a #,##0.00-style format
 * instead (confirmed by reading the raw cell values). Returns the same
 * `meta` shape App\Exports\Concerns\StylesLegacyReportSheet already knows
 * how to style, so that trait/its companion App\Exports\SalesListingExport
 * are reused unmodified.
 */
trait BuildsSalesSummaryReport
{
    /**
     * @param  array<int, mixed>  $headingRow
     * @param  array<int, array<int, mixed>>  $bodyRows  already includes its own trailing "Total By Header" row
     * @param  array<int, array{0: ?string, 1: float, 2: float, 3: float}>  $taxGroups  [code, rate, taxable, tax] — one row per distinct code, null code -> "NON-PPN"
     * @param  array<int, string>  $numberFormatColumns  the Excl.Tax/Disc/Tax/Incl.Tax column letters
     * @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>}
     */
    protected function buildSalesSummaryReport(
        string $title,
        string $periodLabel,
        string $companyName,
        array $headingRow,
        array $bodyRows,
        array $taxGroups,
        string $printedBy,
        string $lastColumn,
        array $numberFormatColumns,
        ?string $dateColumn = 'A',
        array $rightAlignColumns = [],
    ): array {
        $rows = [
            [$title],
            [$periodLabel],
            [''],
            [''],
            [$companyName, '', '', now()->format('d/m/Y H:i:s')],
            [''],
            [''],
            $headingRow,
        ];
        $boldRows = [8];

        $firstBodyRow = count($rows) + 1;
        array_push($rows, ...$bodyRows);
        $lastBodyRow = count($rows);
        $tableRange = [8, $lastBodyRow];

        $rows[] = [''];
        $rows[] = [''];
        $rows[] = ['TAX SUMMARY'];
        $boldRows[] = count($rows);
        $rows[] = ['Code', 'Rate', 'Goods Amount', 'Tax Amount'];
        $boldRows[] = count($rows);

        $firstTaxRow = count($rows) + 1;
        $totalTaxable = 0.0;
        $totalTax = 0.0;
        foreach ($taxGroups as [$code, $rate, $taxable, $tax]) {
            $rows[] = [$code ?? 'NON-PPN', $this->formatSummaryTaxRate($rate), round($taxable, 2), round($tax, 2)];
            $totalTaxable += $taxable;
            $totalTax += $tax;
        }
        $rows[] = ['', '', round($totalTaxable, 2), round($totalTax, 2)];
        $lastTaxRow = count($rows);

        $rows[] = [''];
        $rows[] = ['Printed By :', $printedBy];

        $numberFormatRanges = [];
        if ($numberFormatColumns !== []) {
            $numberFormatRanges[] = ['columns' => $numberFormatColumns, 'format' => '#,##0.00', 'rows' => [$firstBodyRow, $lastBodyRow]];
        }
        $numberFormatRanges[] = ['columns' => ['C', 'D'], 'format' => '#,##0.00', 'rows' => [$firstTaxRow, $lastTaxRow]];
        if ($dateColumn !== null) {
            $numberFormatRanges[] = ['columns' => [$dateColumn], 'format' => 'dd/mm/yyyy', 'rows' => [$firstBodyRow, $lastBodyRow]];
        }

        return [
            'rows' => $rows,
            'meta' => [
                'lastColumn' => $lastColumn,
                'boldRows' => $boldRows,
                'headerRow' => 8,
                'numberFormatRanges' => $numberFormatRanges,
                'borderRange' => $tableRange,
                'rightAlignColumns' => $rightAlignColumns,
                'alignRange' => $rightAlignColumns !== [] ? $tableRange : null,
            ],
        ];
    }

    /**
     * Groups pre-resolved tax lines by code — one row can contribute several
     * (Delivery's per-item tax) or exactly one (header-level tax, or a
     * single NON-PPN bucket for modules with no linked Tax record at all).
     *
     * @param  Closure(mixed): array<int, array{0: ?string, 1: float, 2: float, 3: float}>  $resolve  (row) => [[code, rate, taxable, tax], ...]
     * @return array<int, array{0: ?string, 1: float, 2: float, 3: float}>
     */
    protected function groupTaxSummary(Collection $rows, Closure $resolve): array
    {
        $groups = [];

        foreach ($rows as $row) {
            foreach ($resolve($row) as [$code, $rate, $taxable, $tax]) {
                $key = $code ?? '__NON_PPN__';
                $groups[$key] ??= ['code' => $code, 'rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0];
                $groups[$key]['taxable'] += $taxable;
                $groups[$key]['tax'] += $tax;
            }
        }

        return array_values(array_map(fn ($g) => [$g['code'], $g['rate'], $g['taxable'], $g['tax']], $groups));
    }

    /**
     * Explicit date_from/date_to when both are set; otherwise derived from
     * the min/max date actually present in the exported set — the "ids"
     * (checked-rows) export path carries no date filter at all.
     */
    protected function summaryPeriodLabel(array $filters, Collection $rows, string $dateField): string
    {
        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            return Carbon::parse($filters['date_from'])->format('d/m/Y').' - '.Carbon::parse($filters['date_to'])->format('d/m/Y');
        }

        $dates = $rows->pluck($dateField)->filter();
        $from = $dates->min();
        $to = $dates->max();

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '').' - '.($to ? Carbon::parse($to)->format('d/m/Y') : '');
    }

    /** A real Excel date serial so the DATE column sorts/filters as a date — see StylesLegacyReportSheet's number-format range. */
    protected function summaryExcelDate(?Carbon $date): float|string
    {
        return $date ? ExcelDate::PHPToExcel($date) : '';
    }

    /** '11' -> '11 %', '0' -> '0 %' — matches the reference exports' own integer-looking rate labels. */
    protected function formatSummaryTaxRate(float $rate): string
    {
        $trimmed = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');

        return ($trimmed === '' ? '0' : $trimmed).' %';
    }
}
