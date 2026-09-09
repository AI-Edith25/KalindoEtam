<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Inventory Valuation report export — one row per (item, warehouse), same set and shape as the on-screen table. */
class InventoryValuationExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Item Code', 'Item Name', 'Warehouse',
            'Opening Qty', 'Opening Value',
            'Qty In', 'Value In',
            'Qty Out', 'Value Out',
            'Closing Qty', 'Unit Cost', 'Closing Value',
        ];
    }

    public function map($row): array
    {
        return [
            $row->item_code,
            $row->item_name,
            $row->warehouse_name,
            $row->opening_qty,
            $row->opening_value,
            $row->qty_in,
            $row->value_in,
            $row->qty_out,
            $row->value_out,
            $row->closing_qty,
            $row->unit_cost,
            $row->closing_value,
        ];
    }
}
