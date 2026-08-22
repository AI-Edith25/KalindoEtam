<?php

namespace App\Exports;

use App\Exports\Concerns\StylesAccountsReceivableAgingSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * AR Detail's "Customer Detail Aging" export. $rows/$meta come from
 * AccountsReceivableAgingReportService::detailReport() — see that class's docblock for the
 * verified aging-bucket rule and the row/footer structure, modeled on the real
 * xlsCustomerDetailAging.xlsx reference file rather than the ticket's prose.
 *
 * WithStrictNullComparison is required, not decorative — PhpSpreadsheet's Worksheet::fromArray()
 * defaults to loose (==) null comparison and silently skips writing any cell whose value equals
 * null under `==`, which a real 0.0 bucket amount (the common case) does in PHP. Same bug class
 * that shipped and was fixed in the Sales Invoice export (commit b005ba5) — not repeating it here.
 */
class AccountsReceivableAgingDetailExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesAccountsReceivableAgingSheet;

    /** @param array<int, mixed> $meta see AccountsReceivableAgingReportService::detailReport()'s return shape */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function array(): array
    {
        return $this->rows;
    }
}
