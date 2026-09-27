<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Generic "the service decides positions and content via a meta array, this trait only executes
 * what it describes" styling glue for FromArray-based exports — originally extracted for
 * AccountsReceivableAgingDetailExport/AccountsReceivableAgingSummaryExport (renamed from
 * StylesAccountsReceivableAgingSheet once StockLedgerSummaryExport needed the exact same
 * mechanism — nothing in this trait was ever AR-specific), now shared by both.
 *
 * $this->meta['columnWidths'], ['mergeRanges'], ['numberFormats'][] = ['range', 'format'],
 * ['freezePane'] = 'A7'-style cell reference, and ['styleRanges'][]: ['range' => 'A5:L5', 'bold'
 * => bool, 'fontName' => ?string, 'fontSize' => ?int, 'hAlign' => ?string, 'vAlign' => ?string,
 * 'borderTop'|'borderBottom'|'borderLeft'|'borderRight'|'borderAll' => bool].
 */
trait StylesMetaDrivenSheet
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

                    if (($entry['bold'] ?? false) || ($entry['italic'] ?? false) || isset($entry['fontName']) || isset($entry['fontSize'])) {
                        $font = $style->getFont();
                        if (array_key_exists('bold', $entry)) {
                            $font->setBold($entry['bold']);
                        }
                        if (array_key_exists('italic', $entry)) {
                            $font->setItalic($entry['italic']);
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

                    if (isset($entry['background'])) {
                        $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($entry['background']);
                    }

                    if (isset($entry['fontColor'])) {
                        $style->getFont()->getColor()->setRGB($entry['fontColor']);
                    }

                    if ($entry['borderAll'] ?? false) {
                        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    } else {
                        $borders = $style->getBorders();
                        if ($entry['borderTop'] ?? false) {
                            $borders->getTop()->setBorderStyle($entry['borderTopThick'] ?? false ? Border::BORDER_THICK : Border::BORDER_THIN);
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

                if (isset($this->meta['freezePane'])) {
                    $sheet->freezePane($this->meta['freezePane']);
                }

                if (isset($this->meta['autoFilter'])) {
                    $sheet->setAutoFilter($this->meta['autoFilter']);
                }

                if ($this->meta['autoSize'] ?? false) {
                    foreach ($sheet->getColumnIterator() as $column) {
                        $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
                    }
                }
            },
        ];
    }
}
