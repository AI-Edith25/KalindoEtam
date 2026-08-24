<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Companion to BuildsLegacyReportRows — styles whatever that trait's meta array describes (bold
 * rows, number-format ranges, borders, alignment, freeze pane, autosize). A CSV export built via
 * buildCsvRows() carries an all-but-empty meta (just lastColumn), so every block below is a no-op
 * for it except autosize/BOM — matching the ticket's "CSV = bare header+data, no banner/styling"
 * requirement without a separate code path.
 */
trait StylesLegacyReportSheet
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
                $lastColumn = $this->meta['lastColumn'] ?? 'F';

                foreach ($this->meta['boldRows'] ?? [] as $rowNumber) {
                    $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
                }

                foreach ($this->meta['numberFormatRanges'] ?? [] as $range) {
                    [$start, $end] = $range['rows'];
                    foreach ($range['columns'] as $column) {
                        $sheet->getStyle("{$column}{$start}:{$column}{$end}")->getNumberFormat()->setFormatCode($range['format']);
                    }
                }

                $borderRange = $this->meta['borderRange'] ?? null;
                if ($borderRange !== null) {
                    [$start, $end] = $borderRange;
                    $sheet->getStyle("A{$start}:{$lastColumn}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                }

                $alignRange = $this->meta['alignRange'] ?? null;
                $rightAlignColumns = $this->meta['rightAlignColumns'] ?? [];
                if ($alignRange !== null) {
                    [$start, $end] = $alignRange;
                    $sheet->getStyle("A{$start}:{$lastColumn}{$end}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                    foreach ($rightAlignColumns as $column) {
                        $sheet->getStyle("{$column}{$start}:{$column}{$end}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    }
                }

                if (isset($this->meta['headerRow'])) {
                    $sheet->freezePane('A' . ($this->meta['headerRow'] + 1));
                }

                for ($i = 1; $i <= Coordinate::columnIndexFromString($lastColumn); $i++) {
                    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
                }
            },
        ];
    }
}
