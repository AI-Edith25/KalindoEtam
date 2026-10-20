<?php

namespace App\Exports;

use App\Exports\Concerns\StylesSalesReportSheet;
use App\Models\GoodsReceipt;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Reports > Goods Receipt Report export (/reports/goods-receipts) — one flat row per GR item
 * (not per document), with document columns repeated on every item row so the file stays
 * pivot/filter-friendly in Excel with no merged cells. Distinct from the unrelated, already-
 * shipped Purchase > Goods Receipts exportListing (GoodsReceiptListingDetailExport etc.), which
 * replicates a legacy nested template — do not confuse the two or touch that one for this report.
 */
class GoodsReceiptExport implements FromArray, WithCustomCsvSettings, WithEvents, WithStrictNullComparison
{
    use StylesSalesReportSheet;

    protected const LAST_COLUMN = 'M';

    /** @var array<int, array<int, mixed>> */
    protected array $rows;

    /** @var array<string, mixed> */
    protected array $meta;

    /** @param array<string, mixed> $filters validated IndexGoodsReceiptRequest filters (warehouse_id, date_from, date_to) */
    public function __construct(Collection $receipts, array $filters = [])
    {
        $metaRow = ["Data ditarik pada: {$this->pulledAt()}"];
        $periodRow = ["Periode: {$this->periodLabel($filters, $receipts)}, Lokasi: {$this->locationLabel($filters)}"];
        $headingRow = ['Date', 'Receipt No', 'Purchase No', 'Warehouse', 'Supplier', 'Status', 'Source', 'Item Code', 'Item Name', 'Satuan', 'Qty', 'Unit Price', 'Subtotal'];

        $bodyRows = $this->itemRows($receipts);
        $headerRowNumber = 4;

        $this->rows = [$metaRow, $periodRow, [''], $headingRow, ...$bodyRows];
        $this->meta = [
            'lastColumn' => self::LAST_COLUMN,
            'boldRows' => [$headerRowNumber],
            'freezeRow' => $headerRowNumber + 1,
            'borderRange' => [$headerRowNumber, count($this->rows)],
            'alignRange' => [$headerRowNumber, count($this->rows)],
            'rightAlignColumns' => ['K', 'L', 'M'],
            'numberFormatColumns' => ['K' => '#,##0', 'L' => '#,##0.00', 'M' => '#,##0.00'],
            'numberFormatRange' => [$headerRowNumber + 1, count($this->rows)],
            'dateColumn' => 'A',
            'dateRowRange' => [$headerRowNumber + 1, count($this->rows)],
            'dateFormat' => 'dd/mm/yyyy',
        ];
    }

    public function array(): array
    {
        return $this->rows;
    }

    /** @return array<int, array<int, mixed>> */
    protected function itemRows(Collection $receipts): array
    {
        $rows = [];

        foreach ($receipts as $receipt) {
            /** @var GoodsReceipt $receipt */
            $documentColumns = [
                $this->excelDate($receipt->receipt_date),
                $receipt->document_number,
                $receipt->purchaseOrder?->document_number ?? 'Direct Receipt',
                $receipt->warehouse?->name,
                $receipt->supplier?->supplier_name,
                ucfirst($receipt->status?->value ?? ''),
                ucfirst($receipt->source),
            ];

            if ($receipt->items->isEmpty()) {
                $rows[] = [...$documentColumns, null, null, null, null, null, null];

                continue;
            }

            foreach ($receipt->items as $item) {
                $rows[] = [
                    ...$documentColumns,
                    $item->item_code,
                    $item->item_name,
                    $item->uom,
                    (float) $item->qty,
                    round((float) $item->rate, 2),
                    round((float) $item->amount, 2),
                ];
            }
        }

        return $rows;
    }

    protected function excelDate(?Carbon $date): float|string
    {
        return $date ? ExcelDate::PHPToExcel($date) : '';
    }

    protected function pulledAt(): string
    {
        return Carbon::now()->locale('id')->translatedFormat('d M Y, H:i');
    }

    /** @param array<string, mixed> $filters */
    protected function periodLabel(array $filters, Collection $receipts): string
    {
        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            return Carbon::parse($filters['date_from'])->locale('id')->translatedFormat('d M Y')
                . ' – ' . Carbon::parse($filters['date_to'])->locale('id')->translatedFormat('d M Y');
        }

        $dates = $receipts->pluck('receipt_date')->filter();
        $from = $dates->min();
        $to = $dates->max();

        if ($from === null || $to === null) {
            return '–';
        }

        return $from->locale('id')->translatedFormat('d M Y') . ' – ' . $to->locale('id')->translatedFormat('d M Y');
    }

    /** @param array<string, mixed> $filters */
    protected function locationLabel(array $filters): string
    {
        if (empty($filters['warehouse_id'])) {
            return 'Semua Lokasi';
        }

        return Warehouse::find($filters['warehouse_id'])?->name ?? 'Semua Lokasi';
    }
}
