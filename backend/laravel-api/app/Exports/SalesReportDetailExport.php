<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Sales Report — Detail mode. One unified 10-column table shared by both row shapes (see
 * SalesReportService::detailRows()'s own docblock for the exact column-position alignment) —
 * header rows and item sub-rows are already interleaved in $rows in document order.
 */
class SalesReportDetailExport implements FromArray, WithHeadings
{
    public function __construct(protected array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'DATE / ITEM#', 'DOCUMENT# / DESCRIPTION', 'CUSTOMER# / UOM', 'NAME / QUANTITY', 'DELIVERY TO / UNIT PRICE',
            'DISC', 'TAX', 'T.CODE', 'AMOUNT / LINE AMOUNT', 'REFERENCE 1#',
        ];
    }
}
