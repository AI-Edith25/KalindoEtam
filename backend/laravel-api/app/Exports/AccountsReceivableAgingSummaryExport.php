<?php

namespace App\Exports;

use App\Exports\Concerns\StylesAccountsReceivableAgingSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * AR Detail's "Customer Summary Aging" export — see AccountsReceivableAgingDetailExport's
 * docblock (same $rows/$meta shape, same WithStrictNullComparison rationale). $rows/$meta come
 * from AccountsReceivableAgingReportService::summaryReport().
 */
class AccountsReceivableAgingSummaryExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesAccountsReceivableAgingSheet;

    /** @param array<int, mixed> $meta see AccountsReceivableAgingReportService::summaryReport()'s return shape */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
