<?php

namespace App\Exports;

use App\Exports\Concerns\StylesMetaDrivenSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Stock Ledger export's "Summary" sheet — Location -> Item Group -> Item, B/F + running balance +
 * subtotals at every level. $rows/$meta come from StockLedgerExportService::summaryRows().
 *
 * WithStrictNullComparison is required, not decorative — see AccountsReceivableLedgerExport's
 * docblock for the PhpSpreadsheet loose-null-comparison bug class this avoids (a real 0.0 QTY IN/
 * OUT cell, the common case on every transaction row, would otherwise be silently skipped).
 */
class StockLedgerSummaryExport implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    use StylesMetaDrivenSheet;

    /** @param array<int, mixed> $meta see StockLedgerExportService::summaryRows()'s return shape */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Summary';
    }
}
