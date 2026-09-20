<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Generic flat export shared by all 3 Sales Archive tabs -- headings/rows supplied by the
 * caller (SalesArchiveController), same "imported, not live" banner-row convention as
 * CustomerOutstandingArchiveExport, just data-agnostic since 3 different row shapes share it.
 */
class SalesArchiveExport implements FromArray, WithCustomCsvSettings, WithEvents, WithHeadings, WithStrictNullComparison
{
    public function __construct(
        protected array $headings,
        protected array $rows,
        protected string $sourceNote,
    ) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->setCellValue('A1', $this->sourceNote);
            },
        ];
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true, 'delimiter' => ','];
    }
}
