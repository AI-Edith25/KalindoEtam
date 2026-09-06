<?php

namespace App\Exports;

use App\Models\FifoLayer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * FIFO Layers report export — layer-level detail (one row per layer), same as the on-screen
 * drill-down, not just the grouped subtotals — matches this user's standing preference for
 * invoice/line-level detail over an aggregated-only export (see the AR Detail export).
 */
class FifoValuationExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Item Code', 'Item Name', 'Warehouse', 'Received Date', 'Source Document', 'Qty In', 'Qty Remaining', 'Unit Cost', 'Remaining Value'];
    }

    public function map($row): array
    {
        /** @var FifoLayer&object{item_code: string, item_name: string, warehouse_name: string} $row */
        return [
            $row->item_code,
            $row->item_name,
            $row->warehouse_name,
            $row->received_date?->format('Y-m-d'),
            $row->source_document_number,
            $row->qty_in,
            $row->qty_remaining,
            $row->unit_cost,
            round((float) $row->qty_remaining * (float) $row->unit_cost, 2),
        ];
    }
}
