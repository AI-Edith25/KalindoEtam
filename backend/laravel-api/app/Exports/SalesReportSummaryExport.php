<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;

/**
 * Sales Report — Summary mode. $rows/$boldRows come from
 * SalesReportService::wrapReport(..., SalesReportService::summaryRows(...), ...) — title/date-range/
 * company-timestamp/blank/heading rows, the summary body (including its own trailing "Total By
 * Header" row), the Tax Summary section, then "Printed By" — FromArray, not FromCollection/
 * WithMapping, since there's no further per-row mapping left to do.
 */
class SalesReportSummaryExport implements FromArray, WithCustomCsvSettings, WithEvents
{
    use StylesSalesReportSheet;

    /** @param array<int, int> $boldRows */
    public function __construct(protected array $rows, protected array $boldRows = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
