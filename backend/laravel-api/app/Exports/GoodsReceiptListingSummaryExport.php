<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Goods Receipt Listing — Summary mode. $rows/$meta come from
 * GoodsReceiptReportService::wrapReport(..., summaryRows(...), ...). Distinct from the
 * unrelated, already-shipped GoodsReceiptExport (a plain export feeding the Reports module's
 * Goods Receipt Report page) — do not confuse the two. See SalesReportSummaryExport's own
 * docblock for why WithStrictNullComparison is required.
 */
class GoodsReceiptListingSummaryExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesSalesReportSheet;

    /** @param array<int, mixed> $meta see BuildsListingReportBlock::wrapReport()'s return shape */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
