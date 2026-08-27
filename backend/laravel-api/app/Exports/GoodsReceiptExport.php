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
 * Weight is a per-document total, grouped by unit (never summed across
 * kg/ton) — purely informational, same as everywhere else it's shown.
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
            'Received Qty', 'Total Actual Weight', 'Status',
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
            $this->weightTotalsLabel($row->items),
            ucfirst($row->status?->value ?? ''),
        ];
    }

    protected function weightTotalsLabel(Collection $items): string
    {
        $totalsByUnit = $items
            ->filter(fn ($item) => $item->actual_weight !== null && (float) $item->actual_weight > 0)
            ->groupBy(fn ($item) => $item->weight_unit ?? 'ton')
            ->map(fn ($group) => $group->sum('actual_weight'));

        if ($totalsByUnit->isEmpty()) {
            return '—';
        }

        return $totalsByUnit->map(fn ($total, $unit) => "{$total} {$unit}")->implode(', ');
    }
}
