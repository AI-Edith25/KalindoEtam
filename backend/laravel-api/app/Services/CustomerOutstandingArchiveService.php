<?php

namespace App\Services;

use App\Models\CustomerOutstandingSnapshot;
use App\Models\CustomerOutstandingSnapshotLine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of the standalone "Piutang Customer (Arsip Import)" archive -- pure query/grouping
 * over already-imported snapshot lines, no connection to the live Sales/Invoice/AR module. See
 * App\Services\Import\CustomerOutstandingArchiveImportService for the write/parsing side.
 */
class CustomerOutstandingArchiveService
{
    /** Most-recently-IMPORTED first, not by the file's own snapshot_as_of_date -- a fresh import is the active one regardless of what date its source file happens to carry. */
    public function snapshots(): Collection
    {
        return CustomerOutstandingSnapshot::query()
            ->with('importer:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function latestSnapshot(): ?CustomerOutstandingSnapshot
    {
        return CustomerOutstandingSnapshot::query()->orderByDesc('created_at')->first();
    }

    /**
     * @param  array{customer?: string, invoice_date_from?: string, invoice_date_to?: string, due_date_from?: string, due_date_to?: string, status?: string}  $filters
     */
    public function filteredLines(string $snapshotId, array $filters): Collection
    {
        return CustomerOutstandingSnapshotLine::query()
            ->where('snapshot_id', $snapshotId)
            ->when($filters['customer'] ?? null, fn ($q, $v) => $q->where(fn ($q2) => $q2->where('customer_code', 'like', "%{$v}%")->orWhere('customer_name', 'like', "%{$v}%")))
            ->when($filters['invoice_date_from'] ?? null, fn ($q, $v) => $q->where('txn_date', '>=', $v))
            ->when($filters['invoice_date_to'] ?? null, fn ($q, $v) => $q->where('txn_date', '<=', $v))
            ->when($filters['due_date_from'] ?? null, fn ($q, $v) => $q->where('due_date', '>=', $v))
            ->when($filters['due_date_to'] ?? null, fn ($q, $v) => $q->where('due_date', '<=', $v))
            // No 'lunas' branch -- this file only ever contains unpaid invoices.
            ->when($filters['status'] ?? null, function ($q, $status) {
                match ($status) {
                    'overdue' => $q->where('overdue_amount', '>', 0),
                    'outstanding' => $q->where('overdue_amount', '<=', 0),
                    default => null,
                };
            })
            ->orderBy('customer_code')
            ->orderBy('txn_date')
            ->get();
    }

    /**
     * Header KPIs, unfiltered (matches AP Detail's own summary card convention -- a static
     * queryKey with no filter dependency). "Due this week" is relative to the snapshot's own
     * as-of date, never today's real date -- a frozen snapshot must show the same numbers
     * whenever it's opened, exactly like the aging buckets below.
     *
     * @return array{total_unpaid: float, due_this_week: float, overdue: float}
     */
    public function snapshotSummary(CustomerOutstandingSnapshot $snapshot): array
    {
        $lines = CustomerOutstandingSnapshotLine::query()->where('snapshot_id', $snapshot->id);

        $asOf = $snapshot->snapshot_as_of_date->format('Y-m-d');
        $weekAhead = $snapshot->snapshot_as_of_date->copy()->addDays(7)->format('Y-m-d');

        return [
            'total_unpaid' => (float) (clone $lines)->sum('unpaid_amount'),
            'due_this_week' => (float) (clone $lines)
                ->where('overdue_amount', '<=', 0)
                ->whereBetween('due_date', [$asOf, $weekAhead])
                ->sum('unpaid_amount'),
            'overdue' => (float) (clone $lines)->where('overdue_amount', '>', 0)->sum('overdue_amount'),
        ];
    }

    /** @return array{customers: array<int, array{customer_code: string, customer_name: string, rows: array, subtotal_unpaid: float, subtotal_overdue: float}>, grand_total_unpaid: float, grand_total_overdue: float} */
    public function groupedDetail(string $snapshotId, array $filters): array
    {
        $lines = $this->filteredLines($snapshotId, $filters);

        $customers = $lines
            ->groupBy('customer_code')
            ->map(fn ($rows, $code) => [
                'customer_code' => $code,
                'customer_name' => $rows->first()->customer_name,
                'rows' => $rows->map(fn (CustomerOutstandingSnapshotLine $line) => [
                    'id' => $line->id,
                    'txn_date' => $line->txn_date->format('Y-m-d'),
                    'ref_no' => $line->ref_no,
                    'invoice_amount' => (float) $line->invoice_amount,
                    'paid_amount' => (float) $line->paid_amount,
                    'unpaid_amount' => (float) $line->unpaid_amount,
                    'terms_days' => $line->terms_days,
                    'due_date' => $line->due_date->format('Y-m-d'),
                    'overdue_amount' => (float) $line->overdue_amount,
                    'overdue_days' => $line->overdue_days,
                    'status' => $line->status(),
                ])->values(),
                'subtotal_unpaid' => (float) $rows->sum('unpaid_amount'),
                'subtotal_overdue' => (float) $rows->sum('overdue_amount'),
            ])
            ->values();

        return [
            'customers' => $customers->all(),
            'grand_total_unpaid' => (float) $lines->sum('unpaid_amount'),
            'grand_total_overdue' => (float) $lines->sum('overdue_amount'),
        ];
    }
}
