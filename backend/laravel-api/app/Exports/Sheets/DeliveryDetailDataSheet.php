<?php

namespace App\Exports\Sheets;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * The flat replacement for the legacy xlsDeliveryOrderListing_Detail.xlsx
 * layout: one physical row per DeliveryItem (never per document), a single
 * header row (row 1, via WithHeadings — Maatwebsite Excel writes it before
 * any data row automatically, no manual insertNewRowBefore()/merge needed),
 * and no column that means two different things depending on the row.
 * HEADINGS documents the fixed 25-column order; mapItem() documents where
 * each value actually comes from.
 *
 * FromQuery + WithChunkReading stream the filtered Delivery set (filtering/
 * sorting all happen at the Delivery level — see DeliveryRepository::
 * detailExportQuery()) in bounded batches; map() explodes each Delivery's
 * already-eager-loaded items into N physical rows. Today's volume is only
 * ~1,200 deliveries / ~2,400 lines, but this keeps memory bounded regardless
 * of scale, same reasoning as JournalListExport/SalesJournalExport.
 */
/**
 * WithStrictNullComparison matters here specifically because of the Disc
 * column: PhpSpreadsheet's Worksheet::fromArray() (which every row ultimately
 * goes through) skips writing any cell value that loosely equals its
 * $nullValue — and since Maatwebsite passes $nullValue = null, a literal
 * `0` loosely equals `null` in PHP and would silently come out as a BLANK
 * cell instead of a zero. Strict (`!==`) comparison fixes that while still
 * leaving genuinely-null fields (no customer, no remarks, ...) blank.
 */
class DeliveryDetailDataSheet implements FromQuery, WithHeadings, WithMapping, WithChunkReading, WithEvents, WithTitle, WithStrictNullComparison
{
    protected const HEADINGS = [
        'No', 'Tanggal', 'No Dokumen', 'Kode Customer', 'Nama Customer',
        'Kode Item', 'Deskripsi Item', 'UOM', 'Quantity', 'Unit Price',
        'Disc', 'Tax', 'Line Amount', 'Reference 1', 'Reference 2',
        'Sales Person', 'Lokasi', 'Status', 'Jatuh Tempo', 'Termin',
        'Kode Pajak', 'Catatan', 'Subtotal Dokumen', 'Tax Dokumen', 'Grand Total Dokumen',
    ];

    /** Date columns (B, S) — see numberFormat range in registerEvents(). */
    protected const DATE_COLUMNS = ['B', 'S'];

    /** Numeric columns (I..M, W..Y) — see numberFormat range in registerEvents(). */
    protected const NUMBER_COLUMNS = ['I', 'J', 'K', 'L', 'M', 'W', 'X', 'Y'];

    protected const LAST_COLUMN = 'Y';

    /** 1-based row counter across the whole export (column "No"), surviving chunk boundaries. */
    protected int $rowNumber = 0;

    public function __construct(
        protected Builder $query,
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

    public function headings(): array
    {
        return self::HEADINGS;
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
            $delivery->salesOrder?->reference,
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

    /**
     * Freeze pane / auto filter / column widths are XLSX-only concerns (the
     * Csv writer ignores worksheet-level properties entirely) — safe to
     * leave unconditional. Number formats are the one thing that DOES leak
     * into CSV output (see dateValue()'s docblock), so those are skipped
     * outright for csv: every value map() already returned is a plain
     * numeric/string with no separators, exactly what a CSV cell needs with
     * no styling at all — applying '#,##0.00' here would otherwise bake
     * thousand-separator text into the CSV via rangeToArray()'s formatData.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:'.self::LAST_COLUMN.'1');
                foreach (range('A', self::LAST_COLUMN) as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                $lastRow = $sheet->getHighestRow();
                if ($this->format === 'csv' || $lastRow < 2) {
                    return;
                }

                foreach (self::DATE_COLUMNS as $column) {
                    $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                }
                foreach (self::NUMBER_COLUMNS as $column) {
                    $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                }
            },
        ];
    }
}
