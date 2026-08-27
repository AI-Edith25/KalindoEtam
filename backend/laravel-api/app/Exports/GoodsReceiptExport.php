<?php

namespace App\Exports;

use App\Models\GoodsReceipt;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Goods Receipts list export — same columns as the on-screen report
 * (GoodsReceiptReportPage.tsx), same row set as GoodsReceiptService::listAll()
 * (every filtered row, not one page). Mirrors PurchaseInvoiceExport's shape.
 */
class GoodsReceiptExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Date', 'Receipt No', 'Purchase No', 'Warehouse', 'Supplier',
            'Received Qty', 'Status',
        ];
    }

    public function map($row): array
    {
        /** @var GoodsReceipt $row */
        return [
            $row->receipt_date?->format('Y-m-d'),
            $row->document_number,
            $row->purchaseOrder?->document_number ?? 'Direct Receipt',
            $row->warehouse?->name,
            $row->supplier?->supplier_name,
            $row->items->sum('qty'),
            ucfirst($row->status?->value ?? ''),
        ];
    }
}
