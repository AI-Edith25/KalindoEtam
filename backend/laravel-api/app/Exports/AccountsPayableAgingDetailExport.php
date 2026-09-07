<?php

namespace App\Exports;

use App\Models\AccountsPayable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * AP Detail's "Aging List" export — same columns as the on-screen table
 * (AccountsPayableDetailReportPage.tsx), same row set as
 * AccountsPayableService::listAll(). No legacy Excel file to match (unlike
 * AR's aging export), so this uses the simpler FromCollection/WithMapping
 * shape already established by PurchaseReturnExport/JournalEntryExport
 * rather than AR's byte-matching FromArray approach.
 */
class AccountsPayableAgingDetailExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Supplier', 'Warehouse', 'Nomor Invoice', 'Tanggal Invoice', 'Umur',
            'Jatuh Tempo', 'Total Invoice', 'Sudah Dibayar', 'Sisa Hutang', 'Status',
        ];
    }

    public function map($row): array
    {
        /** @var AccountsPayable $row */
        $ageInDays = $row->purchaseInvoice?->invoice_date
            ? (int) $row->purchaseInvoice->invoice_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), true)
            : null;
        // paid_amount_computed: the live PaymentEntryAllocation sum attached by
        // AccountsPayableRepository::searchAll() — same ground truth as the on-screen table and
        // AccountsPayableResource, never the accounts_payables.paid_amount cache column.
        $paid = (float) ($row->paid_amount_computed ?? 0);

        return [
            $row->supplier?->supplier_name,
            $row->goodsReceipt?->warehouse?->name,
            $row->purchaseInvoice?->document_number ?? $row->reference_number,
            $row->purchaseInvoice?->invoice_date?->format('Y-m-d'),
            $ageInDays,
            $row->due_date?->format('Y-m-d'),
            (float) $row->amount,
            $paid,
            (float) $row->amount - $paid,
            ucfirst(str_replace('_', ' ', $row->status?->value ?? '')),
        ];
    }
}
