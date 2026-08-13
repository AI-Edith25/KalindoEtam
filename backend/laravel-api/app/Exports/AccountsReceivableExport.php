<?php

namespace App\Exports;

use App\Models\AccountsReceivable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * AR Detail's Export (C2) — same columns as the on-screen table
 * (AccountsReceivableDetailReportPage.tsx), same row set as
 * AccountsReceivableService::listAll() (every filtered row, not one page).
 * One class handles both XLSX and CSV — Excel::download() picks the writer
 * from the filename extension the controller passes.
 */
class AccountsReceivableExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Customer', 'Sales Person', 'Invoice Number', 'Invoice Date', 'Masa (Days)', 'Umur (Days)',
            'Due Date', 'Total Invoice', 'Paid Amount', 'Outstanding Amount', 'Status',
        ];
    }

    public function map($row): array
    {
        /** @var AccountsReceivable $row */
        return [
            $row->customer?->customer_name,
            $row->salesOrder?->salesPerson?->name,
            $row->invoice?->document_number,
            $row->invoice?->invoice_date?->format('Y-m-d'),
            $row->invoice?->termsOfPayment?->days,
            $row->invoice?->invoice_date ? (int) $row->invoice->invoice_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), true) : null,
            $row->due_date?->format('Y-m-d'),
            (float) $row->amount,
            (float) $row->paid_amount,
            (float) $row->amount - (float) $row->paid_amount,
            $row->status?->value,
        ];
    }
}
