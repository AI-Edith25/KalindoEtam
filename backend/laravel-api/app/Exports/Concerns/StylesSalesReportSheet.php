<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Shared by SalesReportSummaryExport/SalesReportDetailExport — both build their $rows/$meta via
 * SalesReportService::wrapReport(), which returns rows plus which rows/columns need styling
 * (bold heading rows, "TAX SUMMARY", its own "Code/Rate/..." header; optionally a date-format
 * column and/or a bordered data range). Only what wrapReport() actually marks gets styled —
 * matches the reference export samples, which have no merged cells and no right-aligned cells.
 *
 * AfterSheet fires for both the CSV and XLSX writers (Maatwebsite always builds a
 * PhpSpreadsheet\Spreadsheet internally before converting to the target format). Bold/border
 * calls are harmless for CSV (the writer just flattens plain cell values, ignoring styling), but
 * the date number-format is NOT decorative for CSV: PhpSpreadsheet's CSV writer renders each
 * cell via NumberFormat::toFormattedString() using its assigned format code (confirmed by
 * reading Worksheet::rangeToArray()/cellToArray(), which the CSV writer calls with
 * $formatData = true) — so a real Excel-date cell with a dd/mm/yyyy format renders as
 * "20/08/2026" in CSV too, not a raw serial number. That mechanism is what lets
 * SalesReportService write one raw date value shared by both formats instead of branching.
 */
trait StylesSalesReportSheet
{
    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->getSheet()->getDelegate();
                $lastColumn = $this->meta['lastColumn'] ?? 'M';

                foreach ($this->meta['boldRows'] ?? [] as $rowNumber) {
                    $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
                }

                $dateColumn = $this->meta['dateColumn'] ?? null;
                $dateRowRange = $this->meta['dateRowRange'] ?? null;
                if ($dateColumn !== null && $dateRowRange !== null) {
                    [$start, $end] = $dateRowRange;
                    $sheet->getStyle("{$dateColumn}{$start}:{$dateColumn}{$end}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                }

                $borderRange = $this->meta['borderRange'] ?? null;
                if ($borderRange !== null) {
                    [$start, $end] = $borderRange;
                    $sheet->getStyle("A{$start}:{$lastColumn}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                }
            },
        ];
    }
}
