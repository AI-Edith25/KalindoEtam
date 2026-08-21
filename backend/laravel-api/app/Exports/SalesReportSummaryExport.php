<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Sales Report — Summary mode. $rows/$boldRows come from
 * SalesReportService::wrapReport(..., SalesReportService::summaryRows(...), ...) — title/date-range/
 * company-timestamp/blank/heading rows, the summary body (including its own trailing "Total By
 * Header" row), the Tax Summary section, then "Printed By" — FromArray, not FromCollection/
 * WithMapping, since there's no further per-row mapping left to do.
 *
 * WithStrictNullComparison is required, not decorative: PhpSpreadsheet's Worksheet::fromArray()
 * defaults to loose (==) null comparison and silently skips writing any cell whose value equals
 * null under `==` — which a real 0 (e.g. DISC/TAX = 0) does in PHP. Without this, every
 * genuinely-zero numeric cell in this report renders blank instead of 0.
 */
class SalesReportSummaryExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesSalesReportSheet;

    /** @param array<int, int> $boldRows */
    public function __construct(protected array $rows, protected array $boldRows = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
