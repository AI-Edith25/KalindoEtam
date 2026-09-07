<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * AP Detail's "Perincian Hutang" export — same per-supplier aging-bucket
 * rows AccountsPayableService::groupedDetail() computes for the on-screen
 * view, plus a trailing TOTAL row. Unlike AR (whose export and on-screen
 * bucket schemes differ for legacy-format reasons), this export and the
 * screen share one definition, so there's nothing to reconcile.
 *
 * @see \App\Services\AccountsPayableService::groupedDetail() for the {rows, total} shape
 */
class AccountsPayableAgingSummaryExport implements FromCollection, WithHeadings, WithMapping
{
    /** @param array{rows: array<int, array<string, mixed>>, total: array<string, float>} $data */
    public function __construct(protected array $data) {}

    public function collection(): Collection
    {
        return collect([...$this->data['rows'], ['supplier_name' => 'TOTAL', ...$this->data['total']]]);
    }

    public function headings(): array
    {
        return [
            'Supplier', 'Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '61-90 Hari', '> 90 Hari', 'Total Hutang',
        ];
    }

    public function map($row): array
    {
        return [
            $row['supplier_name'],
            (float) $row['not_due'],
            (float) $row['due_1_30'],
            (float) $row['due_31_60'],
            (float) $row['due_61_90'],
            (float) $row['due_over_90'],
            (float) $row['total'],
        ];
    }
}
