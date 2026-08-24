<?php

namespace App\Services;

use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Repositories\OpenOrdersRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class OpenOrdersService
{
    use BuildsLegacyReportRows;

    public function __construct(protected OpenOrdersRepository $openOrdersRepository) {}

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->openOrdersRepository->paginate($filters, $filters['sort'] ?? 'outstanding_value', $filters['sort_dir'] ?? 'desc', $perPage);
    }

    /**
     * Fetched-then-aggregated in PHP (not a portable-SQL aggregate) — see OpenOrdersRepository's
     * own docblock for why avg age/overdue value can't cleanly be a cross-database SQL aggregate.
     * Open Orders is a bounded working-set (unfulfilled SO lines only, not a full transaction
     * ledger), so this is the same "fetch the filtered set once, aggregate in PHP" posture
     * AccountsReceivableService::groupedDetail() already uses for an analogous reason.
     *
     * @return array{total_outstanding_value: float, open_so_count: int, overdue_value: float, avg_age_days: float}
     */
    public function kpis(array $filters): array
    {
        $rows = $this->openOrdersRepository->allOutstanding($filters, 'outstanding_value', 'desc');

        $today = Carbon::today();
        $overdueValue = 0.0;
        $totalAge = 0;

        foreach ($rows as $row) {
            $totalAge += Carbon::parse($row->order_date)->diffInDays($today);
            if ($row->expected_delivery_date !== null && Carbon::parse($row->expected_delivery_date)->isPast()) {
                $overdueValue += (float) $row->outstanding_value;
            }
        }

        $count = $rows->count();

        return [
            'total_outstanding_value' => (float) $rows->sum('outstanding_value'),
            'open_so_count' => $rows->pluck('sales_order_id')->unique()->count(),
            'overdue_value' => $overdueValue,
            'avg_age_days' => $count > 0 ? round($totalAge / $count, 1) : 0.0,
        ];
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $rows = $this->openOrdersRepository->allOutstanding($filters, $filters['sort'] ?? 'outstanding_value', $filters['sort_dir'] ?? 'desc');
        $kpis = $this->kpis($filters);
        $today = Carbon::today();

        $headingRow = [
            'SO DATE', 'SALES NO', 'CUSTOMER', 'SALES PERSON', 'BRANCH', 'ITEM',
            'QTY ORDERED', 'QTY DELIVERED', 'QTY INVOICED', 'QTY OUTSTANDING', 'OUTSTANDING VALUE',
            'DELIVERY STATUS', 'INVOICE STATUS', 'AGE (DAYS)', 'OVERDUE',
        ];

        $bodyRows = $rows->map(function ($row) use ($today) {
            $qtyOrdered = (int) $row->qty_ordered;
            $qtyDelivered = (int) $row->qty_delivered;
            $qtyInvoiced = (int) $row->qty_invoiced;
            $overdue = $row->expected_delivery_date !== null && Carbon::parse($row->expected_delivery_date)->isPast();

            return [
                $this->excelDate(Carbon::parse($row->order_date)), $row->document_number, $row->customer_name,
                $row->sales_person_name ?? 'Unassigned', $row->branch_name ?? '—', $row->item_name,
                $qtyOrdered, $qtyDelivered, $qtyInvoiced, (int) $row->qty_outstanding, (float) $row->outstanding_value,
                $qtyDelivered <= 0 ? 'Not Delivered' : ($qtyDelivered < $qtyOrdered ? 'Partially Delivered' : 'Fully Delivered'),
                $qtyInvoiced <= 0 ? 'Not Invoiced' : ($qtyInvoiced < $qtyOrdered ? 'Partially Invoiced' : 'Fully Invoiced'),
                Carbon::parse($row->order_date)->diffInDays($today),
                $overdue ? 'Yes' : 'No',
            ];
        })->all();

        $totalsRow = [
            'Grand Total', '', '', '', '', '', $rows->sum('qty_ordered'), $rows->sum('qty_delivered'), $rows->sum('qty_invoiced'),
            $rows->sum('qty_outstanding'), $kpis['total_outstanding_value'], '', '', '', '',
        ];

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, 'O');
        }

        return $this->buildXlsxRows(
            title: 'OPEN ORDERS REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            totalsRow: $totalsRow,
            lastColumn: 'O',
            numberFormatColumns: ['G', 'H', 'I', 'J', 'K'],
            dateColumn: 'A',
            rightAlignColumns: ['G', 'H', 'I', 'J', 'K', 'N'],
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('OpenOrdersReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    protected function excelDate(Carbon $date): float
    {
        return \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-') . ' - ' . ($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
