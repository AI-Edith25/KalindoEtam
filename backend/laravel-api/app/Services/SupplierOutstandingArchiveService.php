<?php

namespace App\Services;

use App\Models\SupplierOutstandingSnapshot;
use App\Models\SupplierOutstandingSnapshotLine;
use Illuminate\Database\Eloquent\Collection;

/** AP mirror of CustomerOutstandingArchiveService -- see that class's own docblock. */
class SupplierOutstandingArchiveService
{
    /** Most-recently-IMPORTED first -- a fresh import is the active one regardless of its own snapshot_as_of_date. */
    public function snapshots(): Collection
    {
        return SupplierOutstandingSnapshot::query()
            ->with('importer:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function latestSnapshot(): ?SupplierOutstandingSnapshot
    {
        return SupplierOutstandingSnapshot::query()->orderByDesc('created_at')->first();
    }

    /** @param  array{supplier?: string, invoice_date_from?: string, invoice_date_to?: string, due_date_from?: string, due_date_to?: string, status?: string}  $filters */
    public function filteredLines(string $snapshotId, array $filters): Collection
    {
        return SupplierOutstandingSnapshotLine::query()
            ->where('snapshot_id', $snapshotId)
            ->when($filters['supplier'] ?? null, fn ($q, $v) => $q->where(fn ($q2) => $q2->where('supplier_code', 'like', "%{$v}%")->orWhere('supplier_name', 'like', "%{$v}%")))
            ->when($filters['invoice_date_from'] ?? null, fn ($q, $v) => $q->where('txn_date', '>=', $v))
            ->when($filters['invoice_date_to'] ?? null, fn ($q, $v) => $q->where('txn_date', '<=', $v))
            ->when($filters['due_date_from'] ?? null, fn ($q, $v) => $q->where('due_date', '>=', $v))
            ->when($filters['due_date_to'] ?? null, fn ($q, $v) => $q->where('due_date', '<=', $v))
            ->when($filters['status'] ?? null, function ($q, $status) {
                match ($status) {
                    'overdue' => $q->where('overdue_amount', '>', 0),
                    'outstanding' => $q->where('overdue_amount', '<=', 0),
                    default => null,
                };
            })
            ->orderBy('supplier_code')
            ->orderBy('txn_date')
            ->get();
    }

    /** @return array{total_unpaid: float, due_this_week: float, overdue: float} */
    public function snapshotSummary(SupplierOutstandingSnapshot $snapshot): array
    {
        $lines = SupplierOutstandingSnapshotLine::query()->where('snapshot_id', $snapshot->id);

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

    /** @return array{suppliers: array<int, array{supplier_code: string, supplier_name: string, rows: array, subtotal_unpaid: float, subtotal_overdue: float}>, grand_total_unpaid: float, grand_total_overdue: float} */
    public function groupedDetail(string $snapshotId, array $filters): array
    {
        $lines = $this->filteredLines($snapshotId, $filters);

        $suppliers = $lines
            ->groupBy('supplier_code')
            ->map(fn ($rows, $code) => [
                'supplier_code' => $code,
                'supplier_name' => $rows->first()->supplier_name,
                'rows' => $rows->map(fn (SupplierOutstandingSnapshotLine $line) => [
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
            'suppliers' => $suppliers->all(),
            'grand_total_unpaid' => (float) $lines->sum('unpaid_amount'),
            'grand_total_overdue' => (float) $lines->sum('overdue_amount'),
        ];
    }
}
