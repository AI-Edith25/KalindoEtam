<?php

namespace App\Repositories;

use App\Enums\AccountsPayableStatus;
use App\Models\AccountsPayable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountsPayableRepository extends BaseRepository
{
    public function __construct(AccountsPayable $model)
    {
        parent::__construct($model);
    }

    /**
     * Locks every targeted row for PaymentEntryAllocationService::
     * allocateBatch()'s transaction, ordered by id so two concurrent
     * batches touching an overlapping set always acquire their locks in
     * the same order — prevents a deadlock instead of just detecting one.
     * Mirrors AccountsReceivableRepository::lockManyForUpdate().
     */
    public function lockManyForUpdate(array $ids): Collection
    {
        return $this->model->query()
            ->whereIn('id', array_unique($ids))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function applySettlement(AccountsPayable $accountsPayable, float $paidAmount, AccountsPayableStatus $status): void
    {
        $accountsPayable->update(['paid_amount' => $paidAmount, 'status' => $status]);
    }

    /** Used by PurchaseReturnService (via AccountsPayableService::writeDown()/restoreWriteDown()) — reduces/restores the payable's face amount, distinct from paid_amount. Mirrors AccountsReceivableRepository::applyWriteDown(). */
    public function applyWriteDown(AccountsPayable $accountsPayable, float $amount, float $creditedAmount, AccountsPayableStatus $status): void
    {
        $accountsPayable->update(['amount' => $amount, 'credited_amount' => $creditedAmount, 'status' => $status]);
    }

    /**
     * AP Detail's ground truth for "Sudah Dibayar": a live SUM over
     * payment_entry_allocations, not the accounts_payables.paid_amount
     * cache column — per the report's own requirement, deliberately
     * independent of whatever cache-sync state that column is in. One
     * grouped subquery, joined once per read method below (never N+1).
     * Reversed allocations (is_reversed) are excluded, same as every other
     * allocation-sum in this codebase.
     */
    private function allocatedAmountSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('payment_entry_allocations')
            ->select('accounts_payable_id', DB::raw('SUM(allocated_amount) as paid_amount'))
            ->where('is_reversed', false)
            ->groupBy('accounts_payable_id');
    }

    /**
     * Shared filter definition for search()/searchAll()/outstandingTotal()/
     * groupedBySupplierAgingBuckets() — all four need the identical
     * where-clause set (and the same allocated-amount join, aliased
     * `alloc`), so it's built once instead of duplicated. Mirrors
     * AccountsReceivableRepository::filteredQuery() exactly, minus
     * branch_id/sales_person_id (no Branch or Sales Person concept anywhere
     * in the Purchase domain) and swapping in warehouse_id (a real Purchase
     * dimension: every AccountsPayable row has a NOT NULL Goods Receipt,
     * which always has a NOT NULL warehouse_id — one hop, no fallback chain
     * needed, unlike AR's branch_id).
     *
     * Every AccountsPayable row only ever exists for a Submitted Purchase
     * Invoice (PurchaseInvoiceService::submit() is the sole creator) — a
     * Draft invoice simply has no row here yet, so there's no separate
     * "exclude Draft" filter to apply.
     *
     * aging_bucket is a ceiling filter ("overdue up to N days"), same
     * semantics/options as the AR filter dropdown — this is a *filter*
     * convention, distinct from the discrete, due-date-anchored 5-bucket
     * split groupedBySupplierAgingBuckets() computes for "Perincian Hutang"
     * (that one has its own bucket definition, see that method's docblock).
     */
    private function filteredQuery(array $filters): Builder
    {
        return $this->model->query()
            ->leftJoinSub($this->allocatedAmountSubquery(), 'alloc', 'alloc.accounts_payable_id', '=', 'accounts_payables.id')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('accounts_payables.status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('accounts_payables.supplier_id', $supplierId))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->whereHas('goodsReceipt', fn ($grQuery) => $grQuery->where('warehouse_id', $warehouseId)))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('accounts_payables.due_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('accounts_payables.due_date', '<=', $date))
            ->when($filters['invoice_date_from'] ?? null, fn ($query, $date) => $query->whereHas('purchaseInvoice', fn ($invoiceQuery) => $invoiceQuery->whereDate('invoice_date', '>=', $date)))
            ->when($filters['invoice_date_to'] ?? null, fn ($query, $date) => $query->whereHas('purchaseInvoice', fn ($invoiceQuery) => $invoiceQuery->whereDate('invoice_date', '<=', $date)))
            ->when($filters['invoice_ids'] ?? null, fn ($query, $ids) => $query->whereIn('accounts_payables.invoice_id', $ids))
            ->when($filters['aging_bucket'] ?? null, fn ($query, $bucket) => match ($bucket) {
                '30' => $query->whereDate('accounts_payables.due_date', '>=', now()->subDays(30))->whereDate('accounts_payables.due_date', '<=', now()->subDay()),
                '45' => $query->whereDate('accounts_payables.due_date', '>=', now()->subDays(45))->whereDate('accounts_payables.due_date', '<=', now()->subDay()),
                '60' => $query->whereDate('accounts_payables.due_date', '>=', now()->subDays(60))->whereDate('accounts_payables.due_date', '<=', now()->subDay()),
                '90' => $query->whereDate('accounts_payables.due_date', '>=', now()->subDays(90))->whereDate('accounts_payables.due_date', '<=', now()->subDay()),
                'over_180' => $query->whereDate('accounts_payables.due_date', '<', now()->subDays(180)),
                default => $query,
            });
    }

    // Ascending by due_date (oldest first) — deliberately the opposite of AR's ->latest('due_date').
    // Report's own spec: "paling lama terlambat di atas" (longest-overdue at the top), which is the
    // earliest/oldest due_date, not the newest.

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->select('accounts_payables.*')
            ->selectRaw('COALESCE(alloc.paid_amount, 0) as paid_amount_computed')
            ->with(['supplier', 'purchaseInvoice', 'goodsReceipt.warehouse'])
            ->orderBy('accounts_payables.due_date')
            ->paginate($perPage);
    }

    /** Same filters as search() but unpaginated — for AP Detail's Export and "list-all" uses, mirrors AccountsReceivableRepository::searchAll(). */
    public function searchAll(array $filters): Collection
    {
        return $this->filteredQuery($filters)
            ->select('accounts_payables.*')
            ->selectRaw('COALESCE(alloc.paid_amount, 0) as paid_amount_computed')
            ->with(['supplier', 'purchaseInvoice', 'goodsReceipt.warehouse'])
            ->orderBy('accounts_payables.due_date')
            ->get();
    }

    /** Single-row version of the same ground-truth allocation sum used by search()/searchAll() — for show(), which resolves its model via route binding rather than through filteredQuery(). */
    public function paidAmountFor(string $accountsPayableId): float
    {
        return (float) DB::table('payment_entry_allocations')
            ->where('accounts_payable_id', $accountsPayableId)
            ->where('is_reversed', false)
            ->sum('allocated_amount');
    }

    /** AP Detail Report's "Total Hutang" card — same filters and same allocation-sum join as search(), never a second definition. Mirrors AccountsReceivableRepository::outstandingTotal(). */
    public function outstandingTotal(array $filters): float
    {
        return (float) $this->filteredQuery($filters)
            ->selectRaw('COALESCE(SUM(accounts_payables.amount - COALESCE(alloc.paid_amount, 0)), 0) as outstanding')
            ->value('outstanding');
    }

    /**
     * "Perincian Hutang" — one SQL GROUP BY query, aggregated in the
     * database (not PHP-side, unlike AR's groupedDetail() — AP has no
     * Sales-Person level to nest under, so a flat per-supplier query with
     * bucket columns is both simpler and the actual shape the ticket wants).
     * Buckets are due-date-anchored (Belum Jatuh Tempo / 1-30 / 31-60 /
     * 61-90 / >90 hari), a different, simpler scheme than AR's export-only
     * calendar-month-since-invoice-date buckets (which exist purely to
     * byte-match one legacy Excel file and have no on-screen equivalent).
     * Boundaries are plain date-literal comparisons (PHP-computed, bound as
     * parameters) rather than DATEDIFF()/julianday() — portable across
     * MySQL and the SQLite-backed test suite, same rule as every other
     * date calc in this codebase. Outstanding-per-row (amount minus its
     * live allocation sum, via the same `alloc` join as every other method
     * here) is what gets bucketed, not the amount alone.
     *
     * @return array<int, array{supplier_id: string, supplier_name: string, not_due: float, due_1_30: float, due_31_60: float, due_61_90: float, due_over_90: float, total: float}>
     */
    public function groupedBySupplierAgingBuckets(array $filters): array
    {
        $today = now()->toDateString();
        $minus30 = now()->subDays(30)->toDateString();
        $minus60 = now()->subDays(60)->toDateString();
        $minus90 = now()->subDays(90)->toDateString();
        $outstandingExpr = 'accounts_payables.amount - COALESCE(alloc.paid_amount, 0)';

        $rows = $this->filteredQuery($filters)
            ->join('suppliers', 'suppliers.id', '=', 'accounts_payables.supplier_id')
            ->selectRaw('accounts_payables.supplier_id, suppliers.supplier_name')
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts_payables.due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as not_due", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts_payables.due_date < ? AND accounts_payables.due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_1_30", [$today, $minus30])
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts_payables.due_date < ? AND accounts_payables.due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_31_60", [$minus30, $minus60])
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts_payables.due_date < ? AND accounts_payables.due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_61_90", [$minus60, $minus90])
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts_payables.due_date < ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_over_90", [$minus90])
            ->groupBy('accounts_payables.supplier_id', 'suppliers.supplier_name')
            ->orderBy('suppliers.supplier_name')
            ->get();

        return $rows->map(fn ($row) => [
            'supplier_id' => $row->supplier_id,
            'supplier_name' => $row->supplier_name,
            'not_due' => (float) $row->not_due,
            'due_1_30' => (float) $row->due_1_30,
            'due_31_60' => (float) $row->due_31_60,
            'due_61_90' => (float) $row->due_61_90,
            'due_over_90' => (float) $row->due_over_90,
            'total' => (float) $row->not_due + (float) $row->due_1_30 + (float) $row->due_31_60 + (float) $row->due_61_90 + (float) $row->due_over_90,
        ])->all();
    }

    /**
     * Dashboard's "Outstanding Payable" card. `total_outstanding` delegates
     * to outstandingTotal() with no filters — routing through the one
     * ground-truth (PaymentEntryAllocation-based) method makes Dashboard
     * and AP Detail's default figure a structural guarantee, never two
     * independently-computed numbers that could drift apart.
     */
    public function outstandingSummary(): array
    {
        $notPaid = AccountsPayableStatus::PAID->value;

        return [
            'total_outstanding' => $this->outstandingTotal([]),
            'count' => $this->model->query()->where('status', '!=', $notPaid)->count(),
        ];
    }
}
