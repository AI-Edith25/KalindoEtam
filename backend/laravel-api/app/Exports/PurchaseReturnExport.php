<?php

namespace App\Exports;

use App\Models\PurchaseReturn;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Purchase Returns list export — same columns as the on-screen table
 * (PurchaseReturnListPage.tsx), same row set as
 * PurchaseReturnService::listAll(). Mirrors JournalEntryExport's shape.
 */
class PurchaseReturnExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Return No', 'Purchase Invoice', 'Supplier', 'Reason', 'Date', 'Amount', 'Status',
        ];
    }

    public function map($row): array
    {
        /** @var PurchaseReturn $row */
        return [
            $row->document_number,
            $row->purchaseInvoice?->document_number,
            $row->supplier?->supplier_name,
            ucwords(str_replace('_', ' ', $row->reason?->value ?? '')),
            $row->return_date?->format('Y-m-d'),
            (float) $row->total_amount,
            ucfirst($row->status?->value ?? ''),
        ];
    }
}
