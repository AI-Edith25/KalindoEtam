<?php

namespace App\Services;

use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Repositories\PoTrackingRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PoTrackingService
{
    use BuildsLegacyReportRows;

    protected const STATUS_LABELS = ['not_received' => 'Belum Diterima', 'partial' => 'Sebagian', 'complete' => 'Lengkap'];

    public function __construct(protected PoTrackingRepository $poTrackingRepository) {}

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->poTrackingRepository->paginate($filters, $filters['sort'] ?? 'order_date', $filters['sort_dir'] ?? 'asc', $perPage);
    }

    public function items(string $purchaseOrderId): Collection
    {
        return $this->poTrackingRepository->items($purchaseOrderId)->map(fn ($row) => [
            'item_name' => $row->item_name,
            'ordered_qty' => (float) $row->ordered_qty,
            'received_qty' => (float) $row->received_qty,
            'remaining_qty' => (float) $row->remaining_qty,
        ]);
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $rows = $this->poTrackingRepository->allFiltered($filters, $filters['sort'] ?? 'order_date', $filters['sort_dir'] ?? 'asc');

        $headingRow = ['TANGGAL PO', 'NO PO', 'SUPPLIER', 'NILAI PO', 'QTY DIPESAN', 'QTY DITERIMA', 'SISA', '% TERPENUHI', 'STATUS PENERIMAAN'];

        $bodyRows = $rows->map(fn ($row) => [
            $this->excelDate(Carbon::parse($row->order_date)), $row->document_number, $row->supplier_name,
            (float) $row->total_amount, (float) $row->ordered_qty, (float) $row->received_qty, (float) $row->remaining_qty,
            (float) $row->fulfillment_pct, self::STATUS_LABELS[$row->receiving_status] ?? $row->receiving_status,
        ])->all();

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, 'I');
        }

        return $this->buildXlsxRows(
            title: 'PO TRACKING REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            lastColumn: 'I',
            numberFormatColumns: ['D', 'E', 'F', 'G', 'H'],
            dateColumn: 'A',
            rightAlignColumns: ['D', 'E', 'F', 'G', 'H'],
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('PoTrackingReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    protected function excelDate(Carbon $date): float
    {
        return Date::PHPToExcel($date);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-').' - '.($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
