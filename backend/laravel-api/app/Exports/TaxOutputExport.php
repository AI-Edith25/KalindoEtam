<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** PPN Keluaran export — same columns as the on-screen table, same row set as TaxReportService::outputTaxAll(). $rows are stdClass (raw query-builder rows), same shape TaxReportRowResource formats for the API. */
class TaxOutputExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Tanggal', 'No Invoice', 'Customer', 'NPWP', 'Kode Pajak', 'Tarif', 'DPP', 'PPN', 'Total'];
    }

    public function map($row): array
    {
        return [
            $row->document_date,
            $row->document_number,
            $row->party_name,
            '—', // No NPWP field on Customer — see the report's own D-0d finding.
            $row->tax_code ?? '—',
            $row->tax_rate !== null ? (float) $row->tax_rate : null,
            (float) $row->dpp,
            (float) $row->ppn,
            (float) $row->dpp + (float) $row->ppn,
        ];
    }
}
