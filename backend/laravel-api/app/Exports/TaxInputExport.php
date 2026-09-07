<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** PPN Masukan export — same columns as the on-screen table, same row set as TaxReportService::inputTaxAll(). Kode Pajak/Tarif are always blank (Purchase Invoice has no tax-code trail anywhere — see the report's own D-0a finding). */
class TaxInputExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Tanggal', 'No Invoice', 'Supplier', 'NPWP', 'Kode Pajak', 'Tarif', 'DPP', 'PPN', 'Total'];
    }

    public function map($row): array
    {
        return [
            $row->document_date,
            $row->document_number,
            $row->party_name,
            '—',
            '—',
            null,
            (float) $row->dpp,
            (float) $row->ppn,
            (float) $row->dpp + (float) $row->ppn,
        ];
    }
}
