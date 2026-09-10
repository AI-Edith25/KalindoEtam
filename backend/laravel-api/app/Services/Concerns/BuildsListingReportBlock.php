<?php

namespace App\Services\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared by PurchaseOrderReportService/GoodsReceiptReportService — both build a mode's already-
 * shaped $bodyRows, then wrap it in the same title/date-range/company+timestamp/heading/body
 * block. Simpler than SalesReportService::wrapReport() (no Tax Summary footer, no "Printed By",
 * no forced Excel-date-serial column) since neither of these reference templates carry one.
 * Requires the using class to expose `protected CompanyRepository $companyRepository`.
 */
trait BuildsListingReportBlock
{
    /**
     * $timestampColumn is NOT the same letter across every variant — verified against the
     * reference files (PO: always 'D'; GR Summary: 'I'; GR Detail: 'R', its actual last column) —
     * so it's always passed explicitly rather than assumed.
     *
     * @param array<int, array<int, mixed>> $headingRows
     * @param array<int, array<int, mixed>> $bodyRows
     * @param array<string, mixed> $filters
     * @param array<string, string> $numberFormatColumns column letter => format code, applied over the whole heading+body range
     * @return array{rows: array<int, array<int, mixed>>, boldRows: array<int, int>, lastColumn: string, numberFormatColumns: array<string, string>, numberFormatRange: ?array{0: int, 1: int}}
     */
    public function wrapReport(
        string $title,
        string $dateRangeSuffix,
        array $headingRows,
        array $bodyRows,
        array $filters,
        Collection $documents,
        string $dateField,
        string $lastColumn,
        string $timestampColumn,
        array $numberFormatColumns = [],
    ): array {
        $company = $this->companyRepository->defaultOrById(null);

        $timestampIndex = ord($timestampColumn) - ord('A');
        $timestampRow = array_fill(0, $timestampIndex + 1, '');
        $timestampRow[0] = $company->name ?? '';
        $timestampRow[$timestampIndex] = now()->format('d/m/Y H:i:s');

        $rows = [
            [$title],
            [$this->dateRangeLabel($filters, $documents, $dateField) . $dateRangeSuffix],
            [''],
            [''],
            $timestampRow,
            [''],
            [''],
        ];

        $boldRows = [];
        $firstHeadingRow = count($rows) + 1;
        foreach ($headingRows as $headingRow) {
            $rows[] = $headingRow;
            $boldRows[] = count($rows);
        }

        array_push($rows, ...$bodyRows);
        $lastBodyRow = count($rows);

        // Summary's trailing "Total By Header" row (label always in column E, index 4) is bolded
        // same as the heading rows; Detail never appends one, so this is a no-op there.
        if ($bodyRows !== [] && (end($bodyRows)[4] ?? null) === 'Total By Header') {
            $boldRows[] = $lastBodyRow;
        }

        return [
            'rows' => $rows,
            'boldRows' => $boldRows,
            'lastColumn' => $lastColumn,
            'numberFormatColumns' => $numberFormatColumns,
            'numberFormatRange' => $numberFormatColumns !== [] ? [$firstHeadingRow, $lastBodyRow] : null,
        ];
    }

    /** Explicit date_from/date_to filter when both are set; otherwise derived from the min/max $dateField actually present in the exported set. */
    protected function dateRangeLabel(array $filters, Collection $documents, string $dateField): string
    {
        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            return Carbon::parse($filters['date_from'])->format('d/m/Y') . ' - ' . Carbon::parse($filters['date_to'])->format('d/m/Y');
        }

        $dates = $documents->pluck($dateField)->filter();
        $from = $dates->min();
        $to = $dates->max();

        return ($from ? $from->format('d/m/Y') : '') . ' - ' . ($to ? $to->format('d/m/Y') : '');
    }

    /** "567,676,757.80" — comma thousands, period decimal (PHP's number_format default) — matches the reference files' own literal Total row text exactly, verified byte-for-byte, even though the numeric data rows above it render per-viewer-locale via their own '#,##0.00' format code instead. */
    protected function formatTotal(float $value): string
    {
        return number_format($value, 2);
    }
}
