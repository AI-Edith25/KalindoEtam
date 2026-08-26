<?php

namespace App\Exports;

use App\Models\PurchaseInvoice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Purchase Invoices list export — same columns as the on-screen table
 * (PurchaseInvoiceListPage.tsx), same row set as
 * PurchaseInvoiceService::listAll() (every filtered row, not one page).
 * Mirrors JournalEntryExport's shape.
 */
class PurchaseInvoiceExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Date', 'Document Number', 'Reference', 'Supplier Name',
            'Gross Amount', 'Tax Amount', 'Net Amount', 'Status',
        ];
    }

    public function map($row): array
    {
        /** @var PurchaseInvoice $row */
        return [
            $row->invoice_date?->format('Y-m-d'),
            $row->document_number,
            $row->goodsReceipt?->document_number,
            $row->supplier?->supplier_name,
            (float) $row->subtotal,
            (float) $row->tax_amount,
            (float) $row->grand_total,
            ucfirst($row->status?->value ?? ''),
        ];
    }
}
