<?php

namespace App\Exports;

use App\Exports\Concerns\StylesMetaDrivenSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Stock Ledger export's "Detail" sheet — flat, one row per transaction, every active filter
 * applied (including Voucher Type/Search, unlike the Summary sheet). No header block/metadata,
 * column headers on row 1 so AutoFilter/PivotTable work directly. $rows/$meta come from
 * StockLedgerExportService::detailRows().
 */
class StockLedgerDetailExport implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    use StylesMetaDrivenSheet;

    /** @param array<int, mixed> $meta see StockLedgerExportService::detailRows()'s return shape */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Detail';
    }
}
