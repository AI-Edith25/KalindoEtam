<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Shared by AccountsReceivableAgingDetailExport/AccountsReceivableAgingSummaryExport. Unlike
 * StylesSalesReportSheet (Sales Report's own trait — no merges, no per-range font-family
 * override, no percent-as-text block, autosized columns), this report's golden reference files
 * (xlsCustomerDetailAging.xlsx / xlsCustomerSummaryAging.xlsx) need merged customer-header cells,
 * a J/K/L "Arial 9 bold" quirk on the Detail header row that the rest of that row (Calibri 11
 * bold) doesn't carry, and fixed (not autosized) column widths — different enough that bolting
 * all of this onto the Sales Report trait via optional params would stop it being meaningfully
 * shared. Same AfterSheet/meta-array division of responsibility: the service decides positions
 * and content, this trait only executes what the meta array describes.
 *
 * $this->meta['styleRanges'][]: ['range' => 'A5:L5', 'bold' => bool, 'fontName' => ?string,
 * 'fontSize' => ?int, 'hAlign' => ?string, 'vAlign' => ?string, 'borderTop'|'borderBottom'|
 * 'borderLeft'|'borderRight'|'borderAll' => bool].
 */
trait StylesAccountsReceivableAgingSheet
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

                foreach ($this->meta['columnWidths'] ?? [] as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }

                foreach ($this->meta['mergeRanges'] ?? [] as $range) {
                    $sheet->mergeCells($range);
                }

                foreach ($this->meta['styleRanges'] ?? [] as $entry) {
                    $style = $sheet->getStyle($entry['range']);

                    if (($entry['bold'] ?? false) || isset($entry['fontName'])) {
                        $font = $style->getFont();
                        if (array_key_exists('bold', $entry)) {
                            $font->setBold($entry['bold']);
                        }
                        if (isset($entry['fontName'])) {
                            $font->setName($entry['fontName']);
                        }
                        if (isset($entry['fontSize'])) {
                            $font->setSize($entry['fontSize']);
                        }
                    }

                    if (isset($entry['hAlign']) || isset($entry['vAlign'])) {
                        $alignment = $style->getAlignment();
                        if (isset($entry['hAlign'])) {
                            $alignment->setHorizontal($entry['hAlign']);
                        }
                        if (isset($entry['vAlign'])) {
                            $alignment->setVertical($entry['vAlign']);
                        }
                    }

                    if ($entry['borderAll'] ?? false) {
                        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    } else {
                        $borders = $style->getBorders();
                        if ($entry['borderTop'] ?? false) {
                            $borders->getTop()->setBorderStyle(Border::BORDER_THIN);
                        }
                        if ($entry['borderBottom'] ?? false) {
                            $borders->getBottom()->setBorderStyle(Border::BORDER_THIN);
                        }
                        if ($entry['borderLeft'] ?? false) {
                            $borders->getLeft()->setBorderStyle(Border::BORDER_THIN);
                        }
                        if ($entry['borderRight'] ?? false) {
                            $borders->getRight()->setBorderStyle(Border::BORDER_THIN);
                        }
                    }
                }

                foreach ($this->meta['numberFormats'] ?? [] as $entry) {
                    $sheet->getStyle($entry['range'])->getNumberFormat()->setFormatCode($entry['format']);
                }
            },
        ];
    }
}
