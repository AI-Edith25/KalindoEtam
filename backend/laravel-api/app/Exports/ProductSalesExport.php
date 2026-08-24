<?php

namespace App\Exports;

use App\Exports\Concerns\StylesLegacyReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Product Sales export. $rows/$meta come from ProductSalesService::exportRows() — see
 * BuildsLegacyReportRows/StylesLegacyReportSheet for the shared banner/styling shape every one of
 * the 4 new Sales Report tabs' exports uses.
 *
 * WithStrictNullComparison: a genuinely-zero cell (e.g. DISC/ADJUSTMENT, always 0 — no per-line
 * discount exists anywhere in this system) must render as 0.00, not blank — PhpSpreadsheet's
 * default loose (==) null comparison would otherwise skip writing it (same fix already applied to
 * SalesReportDetailExport for the identical reason).
 */
class ProductSalesExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesLegacyReportSheet;

    protected array $rows;

    protected array $meta;

    /** @param array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} $shaped */
    public function __construct(array $shaped)
    {
        $this->rows = $shaped['rows'];
        $this->meta = $shaped['meta'];
    }

    public function array(): array
    {
        return $this->rows;
    }
}
