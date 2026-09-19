<?php

namespace App\Services;

use App\Enums\ImportBatchStatus;
use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Models\ImportBatch;
use App\Repositories\PurchaseByItemRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PurchaseByItemService
{
    use BuildsLegacyReportRows;

    public function __construct(protected PurchaseByItemRepository $purchaseByItemRepository) {}

    /**
     * Completed "Product Purchase Report" imports — a period-level per-item aggregate, kept
     * deliberately separate from the live Goods-Receipt-based table above rather than summed into
     * it: mixing a period aggregate into that table's precise per-transaction min/max/avg would
     * misrepresent both (see the approved plan). Batch counts are small, so filtering in PHP after
     * a plain Eloquent fetch is simpler than a JSON-path WHERE that would need to work identically
     * on both MySQL (production) and SQLite (tests).
     *
     * @return array<int, array{batch_id: string, period_from: ?string, period_to: ?string, imported_at: ?string, items: array}>
     */
    public function importSnapshots(): array
    {
        return ImportBatch::query()
            ->where('module', 'purchase-history')
            ->where('status', ImportBatchStatus::COMPLETED)
            ->latest('created_at')
            ->get()
            ->filter(fn (ImportBatch $batch) => ($batch->mapping['type'] ?? null) === 'product_purchase_report')
            ->map(fn (ImportBatch $batch) => [
                'batch_id' => $batch->id,
                'period_from' => $batch->preview_summary['period_from'] ?? null,
                'period_to' => $batch->preview_summary['period_to'] ?? null,
                'imported_at' => optional($batch->created_at)->toIso8601String(),
                'items' => $batch->preview_summary['item_snapshot'] ?? [],
            ])
            ->values()
            ->all();
    }

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->purchaseByItemRepository->paginate($filters, $filters['sort'] ?? 'amount', $filters['sort_dir'] ?? 'desc', $perPage);
    }

    public function history(string $itemId, array $filters): Collection
    {
        return $this->purchaseByItemRepository->history($itemId, $filters)->map(fn ($row) => [
            'date' => $row->date,
            'gr_number' => $row->gr_number,
            'po_number' => $row->po_number,
            'supplier_name' => $row->supplier_name,
            'qty' => (float) $row->qty,
            'rate' => (float) $row->rate,
            'amount' => (float) $row->amount,
        ]);
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $rows = $this->purchaseByItemRepository->allGrouped($filters, $filters['sort'] ?? 'amount', $filters['sort_dir'] ?? 'desc');

        $headingRow = ['ITEM CODE', 'ITEM NAME', 'UOM', 'QTY DIBELI', 'NILAI PEMBELIAN', 'HARGA RATA-RATA', 'HARGA TERAKHIR', 'HARGA TERENDAH', 'HARGA TERTINGGI'];

        $bodyRows = $rows->map(fn ($row) => [
            $row->item_code, $row->item_name, $row->uom ?? '', (float) $row->qty, (float) $row->amount,
            $row->avg_price !== null ? (float) $row->avg_price : null,
            $row->last_price !== null ? (float) $row->last_price : null,
            $row->lowest_price !== null ? (float) $row->lowest_price : null,
            $row->highest_price !== null ? (float) $row->highest_price : null,
        ])->all();

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, 'I');
        }

        return $this->buildXlsxRows(
            title: 'PURCHASE BY ITEM REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            lastColumn: 'I',
            numberFormatColumns: ['D', 'E', 'F', 'G', 'H', 'I'],
            rightAlignColumns: ['D', 'E', 'F', 'G', 'H', 'I'],
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('PurchaseByItemReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-').' - '.($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
