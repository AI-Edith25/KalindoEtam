<?php

namespace App\Exports;

use App\Exports\Concerns\StylesLegacyReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/** Customer Sales export. $rows/$meta come from CustomerSalesService::exportRows() — see ProductSalesExport for the shared banner/styling shape every Sales Report tab's export uses. */
class CustomerSalesExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
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
