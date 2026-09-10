<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Goods Receipt Listing — Detail mode. $rows/$meta come from
 * GoodsReceiptReportService::wrapReport(..., detailRows(...), ...). Distinct from the unrelated,
 * already-shipped GoodsReceiptExport — see GoodsReceiptListingSummaryExport's own docblock.
 */
class GoodsReceiptListingDetailExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesSalesReportSheet;

    /** @param array<int, mixed> $meta see BuildsListingReportBlock::wrapReport()'s return shape */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
