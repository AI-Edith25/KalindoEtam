<?php

namespace App\Exports;

use App\Exports\Sheets\DeliveryDetailDataSheet;
use App\Exports\Sheets\DeliveryDetailInfoSheet;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Sales -> Deliveries "Export CSV/XLSX" (mode=detail, the default) — one
 * physical row per delivery LINE, replacing the old document-per-row
 * "detail" export and the legacy xlsDeliveryOrderListing_Detail.xlsx
 * two-row-per-document/merged-cell/dual-purpose-column layout it was
 * modeled on (see DECISIONS/tickets for the full list of what that layout
 * got wrong). Sheet 1 ("Delivery Detail") is the flat data grid; Sheet 2
 * ("Info") carries the report metadata that used to live merged into the
 * data sheet's own header rows.
 *
 * CSV export only ever contains Sheet 1 — PhpSpreadsheet's Csv writer
 * always writes just the active/first sheet (Writer::write() calls
 * setActiveSheetIndex(0) before handing off to the writer, and Csv's own
 * $sheetIndex defaults to 0), so WithMultipleSheets "degrades" correctly
 * with zero extra branching as long as the data sheet is sheets()[0].
 */
class DeliveryDetailExport implements WithMultipleSheets, WithCustomCsvSettings
{
    /** @param array{company: string, title: string, generated_at: string, generated_by: string, filters: array<int, array{0: string, 1: string}>} $meta */
    public function __construct(
        protected Builder $query,
        protected array $meta,
        protected string $format,
    ) {}

    public function sheets(): array
    {
        return [
            new DeliveryDetailDataSheet($this->query, $this->format),
            new DeliveryDetailInfoSheet($this->meta),
        ];
    }

    /** UTF-8 BOM so Excel on Windows opens the comma-delimited file with correct encoding instead of mangling it as ANSI. */
    public function getCsvSettings(): array
    {
        return ['use_bom' => true, 'delimiter' => ','];
    }
}
