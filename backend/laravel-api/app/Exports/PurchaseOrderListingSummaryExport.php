<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Purchase Order Listing — Summary mode. $rows/$meta come from
 * PurchaseOrderReportService::wrapReport(..., summaryRows(...), ...). See
 * SalesReportSummaryExport's own docblock for why WithStrictNullComparison is required
 * (without it, every genuinely-zero DISC cell would render blank instead of 0).
 */
class PurchaseOrderListingSummaryExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesSalesReportSheet;

    /** @param array<int, mixed> $meta see BuildsListingReportBlock::wrapReport()'s return shape */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
