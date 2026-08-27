<?php

namespace App\Exports;

use Closure;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * One generic list export shared by all 5 Sales modules (Orders, Deliveries,
 * Invoices, Credit Notes, Debit Notes) — same columns as whatever the
 * caller's on-screen table shows (see `columns()`/`resolveColumn()` on each
 * *Controller). Mirrors PurchaseInvoiceExport's shape, but column-driven
 * instead of hardcoded, so one class covers every module's own column set
 * plus the "pick which columns to export" option.
 */
class SalesListExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection  $rows
     * @param  array<int, array{key: string, label: string}>  $columns  Ordered, already narrowed to what the caller asked for.
     * @param  Closure(mixed, string): mixed  $resolve  (row, columnKey) => cell value
     */
    public function __construct(
        protected Collection $rows,
        protected array $columns,
        protected Closure $resolve,
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return array_column($this->columns, 'label');
    }

    public function map($row): array
    {
        return array_map(fn (array $column) => ($this->resolve)($row, $column['key']), $this->columns);
    }
}
