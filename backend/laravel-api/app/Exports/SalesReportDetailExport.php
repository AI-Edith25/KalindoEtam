<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;

/**
 * Sales Report — Detail mode. $rows/$boldRows come from
 * SalesReportService::wrapReport(..., SalesReportService::detailRows(...), ...) — title/date-range/
 * company-timestamp/blank/heading rows, then header rows and item sub-rows interleaved in
 * document order (see SalesReportService::detailRows()'s own docblock for the exact
 * column-position alignment), the Tax Summary section, then "Printed By".
 */
class SalesReportDetailExport implements FromArray, WithCustomCsvSettings, WithEvents
{
    use StylesSalesReportSheet;

    /** @param array<int, int> $boldRows */
    public function __construct(protected array $rows, protected array $boldRows = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
