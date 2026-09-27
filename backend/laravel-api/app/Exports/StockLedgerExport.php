<?php

namespace App\Exports;

use App\Services\StockLedgerExportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** The Stock Ledger export's top-level workbook — Summary sheet first, Detail sheet second. */
class StockLedgerExport implements WithMultipleSheets
{
    public function __construct(protected StockLedgerExportService $stockLedgerExportService, protected array $filters) {}

    public function sheets(): array
    {
        $summary = $this->stockLedgerExportService->summaryRows($this->filters);
        $detail = $this->stockLedgerExportService->detailRows($this->filters);

        return [
            new StockLedgerSummaryExport($summary['rows'], $summary['meta']),
            new StockLedgerDetailExport($detail['rows'], $detail['meta']),
        ];
    }
}
