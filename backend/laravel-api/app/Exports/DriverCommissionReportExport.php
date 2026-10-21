<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Reproduces the legacy "komisiSopir.jpeg" template as closely as a flat
 * Delivery-item row set allows: same column set/order (DATE, No. SO, No. DO,
 * CUSTOMER, QTY, No. PLAT, SOPIR, a merged KOMISI header over TARIF/TOTAL),
 * plus an ITEMS column the legacy template didn't have (this report is
 * per-item, the legacy one wasn't) and a "Ditarik pada" pull-date stamp the
 * legacy template also didn't have. Same row set as the on-screen Komisi
 * Sopir table — see DriverCommissionReportService::rows().
 */
class DriverCommissionReportExport implements FromCollection, WithMapping, WithEvents, WithCustomCsvSettings
{
    protected const HEADINGS = ['DATE', 'No. SO', 'No. DO', 'CUSTOMER', 'ITEMS', 'QTY', 'No. PLAT', 'SOPIR', 'TARIF', 'TOTAL'];

    protected const LAST_COLUMN = 'J';

    public function __construct(protected Collection $rows, protected string $format) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function map($row): array
    {
        return [
            $row->date,
            $row->so_number,
            $row->do_number,
            $row->customer,
            $row->item_name,
            $row->qty,
            $row->plat_number,
            $row->driver,
            $row->rate_per_zak,
            $row->total_commission,
        ];
    }

    /**
     * Builds the final sheet on top of the plain data rows FromCollection
     * already wrote starting at row 1 (no WithHeadings — the heading
     * position/shape differs too much between formats for that: CSV gets a
     * flat 1-row header, XLSX reproduces the image's merged KOMISI header
     * spanning TARIF/TOTAL). Both get the "Ditarik pada" pull-date line.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $pulledAt = 'Ditarik pada: '.now()->format('d/m/Y H:i:s');

                if ($this->format === 'csv') {
                    $sheet->insertNewRowBefore(1, 2);
                    $sheet->setCellValue('A1', $pulledAt);
                    $sheet->fromArray([self::HEADINGS], null, 'A2');
                    $sheet->freezePane('A3');

                    return;
                }

                // Row 1: pull-date stamp. Row 3-4: the image's own header shape — every single
                // column's label vertically merged across both rows, except KOMISI which spans
                // I3:J3 horizontally with TARIF/TOTAL as its own row-4 sub-headers.
                $sheet->insertNewRowBefore(1, 4);
                $sheet->setCellValue('A1', $pulledAt);
                $sheet->setCellValue('A3', 'DATE');
                $sheet->setCellValue('B3', 'No. SO');
                $sheet->setCellValue('C3', 'No. DO');
                $sheet->setCellValue('D3', 'CUSTOMER');
                $sheet->setCellValue('E3', 'ITEMS');
                $sheet->setCellValue('F3', 'QTY');
                $sheet->setCellValue('G3', 'No. PLAT');
                $sheet->setCellValue('H3', 'SOPIR');
                $sheet->setCellValue('I3', 'KOMISI');
                $sheet->setCellValue('I4', 'TARIF');
                $sheet->setCellValue('J4', 'TOTAL');

                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $column) {
                    $sheet->mergeCells("{$column}3:{$column}4");
                }
                $sheet->mergeCells('I3:J3');
                $sheet->freezePane('A5');

                $headerRange = 'A3:J4';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle($headerRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                foreach (range('A', self::LAST_COLUMN) as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                $lastDataRow = 4 + $this->rows->count();
                if ($this->rows->count() > 0) {
                    $sheet->getStyle('A5:'.self::LAST_COLUMN.$lastDataRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle("I5:J{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0');
                }
            },
        ];
    }

    /** UTF-8 BOM so Excel on Windows opens the comma-delimited file with correct encoding instead of mangling it as ANSI. */
    public function getCsvSettings(): array
    {
        return ['use_bom' => true, 'delimiter' => ','];
    }
}
