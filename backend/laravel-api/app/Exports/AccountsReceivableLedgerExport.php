<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Kartu Piutang's Export CSV/XLSX — a flat array sheet (header info, blank, column headers,
 * transaction rows, closing balance, blank, aging summary), built from
 * AccountsReceivableService::ledgerFull()'s return shape. WithStrictNullComparison is required,
 * not decorative — PhpSpreadsheet's Worksheet::fromArray() defaults to loose (==) null comparison
 * and silently skips writing any cell whose value equals null under `==`, which a real 0.0 debit/
 * credit cell (the common case on every row) does in PHP — same bug class already fixed in
 * AccountsReceivableAgingDetailExport, not repeating it here.
 */
class AccountsReceivableLedgerExport implements FromArray, WithCustomCsvSettings, WithStrictNullComparison
{
    public function __construct(protected array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ',', 'enclosure' => '"', 'line_ending' => "\n"];
    }
}
