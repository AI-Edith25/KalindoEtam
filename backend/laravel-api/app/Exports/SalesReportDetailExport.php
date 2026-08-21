<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Sales Report — Detail mode. $rows/$boldRows come from
 * SalesReportService::wrapReport(..., SalesReportService::detailRows(...), ...) — title/date-range/
 * company-timestamp/blank/heading rows, then header rows and item sub-rows interleaved in
 * document order (see SalesReportService::detailRows()'s own docblock for the exact
 * column-position alignment), the Tax Summary section, then "Printed By".
 *
 * WithStrictNullComparison is required, not decorative: PhpSpreadsheet's Worksheet::fromArray()
 * defaults to loose (==) null comparison and silently skips writing any cell whose value equals
 * null under `==` — which a real 0 (e.g. DISC = 0.0, always the case for item rows) does in PHP.
 * Without this, every genuinely-zero numeric cell in this report renders blank instead of 0.
 */
class SalesReportDetailExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesSalesReportSheet;

    /** @param array<int, int> $boldRows */
    public function __construct(protected array $rows, protected array $boldRows = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
