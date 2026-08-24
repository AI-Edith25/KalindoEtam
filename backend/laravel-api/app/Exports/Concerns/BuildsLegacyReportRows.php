<?php

namespace App\Exports\Concerns;

/**
 * Shared row/meta assembly for the 4 new Sales Report tabs (Product/Customer/Open Orders/Listing)
 * — one place building the "3 banner rows, blank, column headers, data, totals" XLSX layout so
 * none of the 4 tabs' Services duplicate it. Deliberately separate from SalesReportService::
 * wrapReport() (the older Sales -> Invoices "Laporan Penjualan" export): that method's shape
 * (7-row preamble + a mandatory Tax Summary section) is tailored to its own already-shipped
 * reference files and isn't what this ticket's 4 new tabs need — reusing it here would mean
 * bending a live, working export just to save ~15 lines. See StylesLegacyReportSheet for the
 * companion Export-class styling trait these rows are meant to be read by.
 *
 * XLSX and CSV intentionally build DIFFERENT row sets (see buildXlsxRows()/buildCsvRows()) — the
 * ticket calls for a full banner + formatted numbers on XLSX, but bare headers/data + raw
 * (unformatted) numbers with no banner on CSV, so a CSV re-import never has to strip thousands
 * separators or skip a preamble block.
 */
trait BuildsLegacyReportRows
{
    protected const COMPANY_NAME = 'PT. KALINDO ETAM';

    /**
     * @param  array<int, mixed>  $headingRow
     * @param  array<int, array<int, mixed>>  $bodyRows  already-shaped values (real numbers/dates, not pre-formatted text)
     * @param  array<int, mixed>  $totalsRow  one row, appended directly after the body; [] to omit
     * @param  array<int, string>  $numberFormatColumns  column letters to format as '#,##0.00' over the body+totals range
     * @param  array<int, string>  $rightAlignColumns  column letters to right-align over the header+body+totals range
     * @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>}
     */
    protected function buildXlsxRows(
        string $title,
        string $periodLabel,
        array $headingRow,
        array $bodyRows,
        array $totalsRow = [],
        string $lastColumn = 'F',
        array $numberFormatColumns = [],
        ?string $dateColumn = null,
        string $dateFormat = 'dd/mm/yyyy',
        array $rightAlignColumns = [],
    ): array {
        $rows = [
            [$title],
            [self::COMPANY_NAME],
            [$periodLabel, '', '', now()->format('Y-m-d H:i:s')],
            [''],
            $headingRow,
        ];
        $boldRows = [5];

        $firstBodyRow = count($rows) + 1;
        array_push($rows, ...$bodyRows);
        $lastRow = count($rows);
        $tableRange = [5, $lastRow];

        if ($totalsRow !== []) {
            $rows[] = $totalsRow;
            $boldRows[] = count($rows);
            $lastRow = count($rows);
        }

        $numberFormatRanges = [];
        if ($numberFormatColumns !== []) {
            $numberFormatRanges[] = ['columns' => $numberFormatColumns, 'format' => '#,##0.00', 'rows' => [$firstBodyRow, $lastRow]];
        }
        if ($dateColumn !== null) {
            $numberFormatRanges[] = ['columns' => [$dateColumn], 'format' => $dateFormat, 'rows' => [$firstBodyRow, $lastRow]];
        }

        return [
            'rows' => $rows,
            'meta' => [
                'lastColumn' => $lastColumn,
                'boldRows' => $boldRows,
                'headerRow' => 5,
                'numberFormatRanges' => $numberFormatRanges,
                'borderRange' => [5, $lastRow],
                'rightAlignColumns' => $rightAlignColumns,
                'alignRange' => $rightAlignColumns !== [] ? [5, $lastRow] : null,
            ],
        ];
    }

    /**
     * Bare header + data, no banner, no totals row, no styling meta — CSV's own row set, raw
     * numbers/plain date strings so a re-import never has to undo Excel-style formatting.
     *
     * @param  array<int, mixed>  $headingRow
     * @param  array<int, array<int, mixed>>  $bodyRows
     * @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>}
     */
    protected function buildCsvRows(array $headingRow, array $bodyRows, string $lastColumn = 'F'): array
    {
        return [
            'rows' => [$headingRow, ...$bodyRows],
            'meta' => ['lastColumn' => $lastColumn],
        ];
    }

    /** "{ReportName}_{YYYYMMDD}-{YYYYMMDD}_{generatedAt}.{ext}" — e.g. "ProductSalesReport_20260724-20260824_1236.xlsx". */
    protected function buildFileName(string $reportName, ?string $dateFrom, ?string $dateTo, string $format): string
    {
        $from = $dateFrom ? \Carbon\Carbon::parse($dateFrom)->format('Ymd') : now()->subDays(30)->format('Ymd');
        $to = $dateTo ? \Carbon\Carbon::parse($dateTo)->format('Ymd') : now()->format('Ymd');

        return "{$reportName}_{$from}-{$to}_" . now()->format('Hi') . ".{$format}";
    }
}
