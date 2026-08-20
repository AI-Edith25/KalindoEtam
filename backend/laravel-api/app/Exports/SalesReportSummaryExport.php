<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Sales Report — Summary mode. $rows are already fully shaped by SalesReportService::summaryRows()
 * (including the trailing Total row) — FromArray, not FromCollection/WithMapping, since there's no
 * further per-row mapping left to do.
 */
class SalesReportSummaryExport implements FromArray, WithHeadings
{
    public function __construct(protected array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['DATE', 'DOCUMENT#', 'CUSTOMER#', 'CUSTOMER NAME', 'CURRENCY', 'EXCL.TAX', 'DISC', 'TAX', 'INCL.TAX', 'REFERENCE 1#'];
    }
}
