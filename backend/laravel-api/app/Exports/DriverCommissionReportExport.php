<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Same row set and columns as the on-screen Komisi Sopir table — see DriverCommissionReportService::rows(). */
class DriverCommissionReportExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Tanggal', 'No. SO', 'No. DO', 'Customer', 'Jenis Barang', 'Qty (Zak)', 'No. Plat', 'Sopir', 'Tarif/Zak', 'Total Komisi'];
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
}
