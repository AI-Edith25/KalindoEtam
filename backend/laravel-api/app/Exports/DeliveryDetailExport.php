<?php

namespace App\Exports;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Sales -> Deliveries "Export CSV/XLSX" (mode=detail, the default). One
 * physical row per delivery LINE, replacing the legacy
 * xlsDeliveryOrderListing_Detail.xlsx's two-row-per-document/merged-cell/
 * dual-purpose-column layout with a flat grid — but the report metadata
 * (title/period/company/generated-at) sits at the same A1/A2/A5/D5
 * positions that legacy template used, now on the one data sheet (no
 * separate "Info" sheet, no header row above row 1 for CSV).
 *
 * No WithHeadings — the heading row's position depends on format (row 1 for
 * csv, row 8 for xlsx, once the metadata rows are inserted), so it's written
 * manually in registerEvents() instead, after all data rows are already on
 * the sheet starting at row 1 unshifted.
 *
 * FromQuery + WithChunkReading stream the filtered Delivery set (see
 * DeliveryRepository::detailExportQuery() for filtering/ordering) in
 * bounded batches; map() explodes each Delivery's already-eager-loaded
 * items into N physical rows. Today's volume is only ~1,200 deliveries /
 * ~2,400 lines, but this keeps memory bounded regardless of scale.
 */
class DeliveryDetailExport implements FromQuery, WithMapping, WithChunkReading, WithEvents, WithCustomCsvSettings, WithStrictNullComparison, WithTitle
{
    protected const HEADINGS = [
        'No', 'Tanggal', 'No Dokumen', 'Kode Customer', 'Nama Customer',
        'Kode Item', 'Deskripsi Item', 'UOM', 'Quantity', 'Unit Price',
        'Disc', 'Tax', 'Line Amount', 'Reference', 'Sales Person', 'Lokasi',
        'Status', 'Jatuh Tempo', 'Termin', 'Kode Pajak', 'Catatan',
        'Subtotal Dokumen', 'Tax Dokumen', 'Grand Total Dokumen',
    ];

    /** Date columns (B, R) — see numberFormat range in registerEvents(). */
    protected const DATE_COLUMNS = ['B', 'R'];

    /** Numeric columns (I..M, V..X) — see numberFormat range in registerEvents(). */
    protected const NUMBER_COLUMNS = ['I', 'J', 'K', 'L', 'M', 'V', 'W', 'X'];

    protected const LAST_COLUMN = 'X';

    protected const TITLE = 'DELIVERY ORDER LISTING - DETAIL';

    protected const COMPANY = 'PT. KALINDO ETAM';

    /** 1-based row counter across the whole export (column "No"), surviving chunk boundaries. */
    protected int $rowNumber = 0;

    /** @param array{from: ?Carbon, to: ?Carbon} $period */
    public function __construct(
        protected Builder $query,
        protected array $period,
        protected string $format,
    ) {}

    public function title(): string
    {
        return 'Delivery Detail';
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /** @return array<int, array> One physical row per DeliveryItem — Maatwebsite Excel accepts an array of rows from a single map() call. */
    public function map($delivery): array
    {
        /** @var Delivery $delivery */
        // Delivery carries no subtotal/tax/grand_total columns of its own (see DeliveryResource) —
        // always the sum of its own lines, computed once per document and repeated on every row.
        $subtotal = round((float) $delivery->items->sum('amount'), 2);
        $tax = round((float) $delivery->items->sum('tax_amount'), 2);
        $grandTotal = round($subtotal + $tax, 2);

        return $delivery->items->map(
            fn (DeliveryItem $item) => $this->mapItem($delivery, $item, $subtotal, $tax, $grandTotal)
        )->all();
    }

    protected function mapItem(Delivery $delivery, DeliveryItem $item, float $subtotal, float $tax, float $grandTotal): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $this->dateValue($delivery->delivery_date),
            $delivery->document_number,
            $delivery->customer?->customer_code,
            $delivery->customer?->customer_name,
            $item->item_code,
            $item->item_name,
            $item->uom,
            (float) $item->qty,
            (float) $item->rate,
            // Disc — no per-line discount column exists anywhere in the Sales Order -> Delivery
            // chain (only Invoice has a header-level discount_amount, an unrelated concept).
            0.0,
            (float) $item->tax_amount,
            (float) $item->amount,
            $delivery->salesOrder?->document_number,
            $delivery->salesOrder?->salesPerson?->name,
            $delivery->warehouse?->name,
            $this->statusLabel($delivery),
            $this->dateValue($delivery->due_date),
            $delivery->termsOfPayment?->name,
            $item->tax?->code,
            $delivery->remarks,
            $subtotal,
            $tax,
            $grandTotal,
        ];
    }

    /** Mirrors DeliveryResource::is_invoiced — Delivery's own status enum only knows Pending/Complete; "Invoiced" is derived from the invoices relation. */
    protected function statusLabel(Delivery $delivery): string
    {
        if ($delivery->status === DeliveryStatus::COMPLETE && $delivery->invoices->isNotEmpty()) {
            return 'Invoiced';
        }

        return ucfirst($delivery->status?->value ?? '');
    }

    /**
     * XLSX: a real Excel date serial (see the numberFormat range in
     * registerEvents()) — PhpSpreadsheet's value binder doesn't
     * auto-convert \DateTimeInterface, same reasoning as JournalListExport/
     * SalesReportService::excelDate(). CSV: a plain dd/mm/yyyy string
     * instead — CSV cells have no type, and PhpSpreadsheet's Csv writer
     * bakes in the cell's number format when reading a raw value
     * (Worksheet::rangeToArray()'s $formatData defaults true), so a bare
     * serial would come out as a meaningless integer without one.
     */
    protected function dateValue(?Carbon $date): float|string|null
    {
        if (! $date) {
            return null;
        }

        return $this->format === 'csv' ? $date->format('d/m/Y') : ExcelDate::PHPToExcel($date);
    }

    protected function periodLabel(): string
    {
        return ($this->period['from']?->format('d/m/Y') ?? '-').' - '.($this->period['to']?->format('d/m/Y') ?? '-');
    }

    /**
     * Builds the final sheet layout on top of the plain data rows FromQuery
     * already wrote starting at row 1 (unshifted — no WithHeadings here).
     *
     * CSV: insert 1 row for the heading only — "CSV hanya berisi 1 baris
     * header + data", no metadata, no merges (a CSV cell can't be merged
     * anyway). XLSX: insert 8 rows to reproduce the legacy template's
     * A1/A2/A5/D5 metadata positions with the heading row at 8 and data
     * starting at 9 — still a single heading row, still no merged cells,
     * just relocated. No auto filter (dropped per revision) either way.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                if ($this->format === 'csv') {
                    $sheet->insertNewRowBefore(1, 1);
                    $sheet->fromArray([self::HEADINGS], null, 'A1');
                    $sheet->freezePane('A2');

                    return;
                }

                $sheet->insertNewRowBefore(1, 8);
                $sheet->setCellValue('A1', self::TITLE);
                $sheet->setCellValue('A2', $this->periodLabel());
                $sheet->setCellValue('A5', self::COMPANY);
                $sheet->setCellValue('D5', now()->format('d/m/Y H:i:s'));
                $sheet->fromArray([self::HEADINGS], null, 'A8');
                $sheet->freezePane('A9');

                foreach (range('A', self::LAST_COLUMN) as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                if ($this->rowNumber === 0) {
                    return;
                }

                $lastRow = 8 + $this->rowNumber;
                foreach (self::DATE_COLUMNS as $column) {
                    $sheet->getStyle("{$column}9:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                }
                foreach (self::NUMBER_COLUMNS as $column) {
                    $sheet->getStyle("{$column}9:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                }
            },
        ];
    }

    /** UTF-8 BOM so Excel on Windows opens the comma-delimited file with correct encoding instead of mangling it as ANSI. */
    public function getCsvSettings(): array
    {
        return ['use_bom' => true, 'delimiter' => ','];
    }
}
