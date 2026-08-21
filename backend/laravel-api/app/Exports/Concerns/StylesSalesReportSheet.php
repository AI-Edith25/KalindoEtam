<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Shared by SalesReportSummaryExport/SalesReportDetailExport — both build their $rows via
 * SalesReportService::wrapReport(), which returns ['rows' => ..., 'boldRows' => [...]]. Only the
 * rows SalesReportService itself marks (column heading row(s), "TAX SUMMARY", its own "Code/Rate/
 * ..." header) get bold — matches the reference export samples exactly, which have no merged
 * cells, no right-aligned cells, and no bold title row at all.
 *
 * AfterSheet fires for both the CSV and XLSX writers (Maatwebsite always builds a
 * PhpSpreadsheet\Spreadsheet internally before converting to the target format), so the bold
 * calls are safe here regardless of format — the CSV writer just flattens the styled cells'
 * plain values, ignoring the font styling.
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
                $lastColumn = 'M';

                foreach ($this->boldRows as $rowNumber) {
                    $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
                }
            },
        ];
    }
}
