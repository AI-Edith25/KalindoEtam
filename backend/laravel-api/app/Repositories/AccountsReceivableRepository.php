<?php

namespace App\Repositories;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AccountsReceivableRepository extends BaseRepository
{
    public function __construct(AccountsReceivable $model)
    {
        parent::__construct($model);
    }

    /**
     * Locks every targeted row for PaymentAllocationService::allocateBatch()'s
     * transaction, ordered by id so two concurrent batches touching an
     * overlapping set always acquire their locks in the same order —
     * prevents a deadlock instead of just detecting one.
     */
    public function lockManyForUpdate(array $ids): Collection
    {
        return $this->model->query()
            ->whereIn('id', array_unique($ids))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function applySettlement(AccountsReceivable $accountsReceivable, float $paidAmount, AccountsReceivableStatus $status): void
    {
        $accountsReceivable->update(['paid_amount' => $paidAmount, 'status' => $status]);
    }

    /** Used by CreditNoteService (via AccountsReceivableService::writeDown()/restoreWriteDown()) — reduces/restores the receivable's face amount, distinct from paid_amount. */
    public function applyWriteDown(AccountsReceivable $accountsReceivable, float $amount, float $creditedAmount, AccountsReceivableStatus $status): void
    {
        $accountsReceivable->update(['amount' => $amount, 'credited_amount' => $creditedAmount, 'status' => $status]);
    }

    /** Used by DebitNoteService (via AccountsReceivableService::writeUp()/restoreWriteUp()) — increases/restores the receivable's face amount, symmetric to applyWriteDown(). */
    public function applyWriteUp(AccountsReceivable $accountsReceivable, float $amount, float $debitedAmount, AccountsReceivableStatus $status): void
    {
        $accountsReceivable->update(['amount' => $amount, 'debited_amount' => $debitedAmount, 'status' => $status]);
    }

    /**
     * Shared filter definition for search()/outstandingTotal() — both need the
     * identical where-clause set, so it's built once instead of duplicated.
     * aging_bucket is a ceiling filter ("overdue up to N days"), not a
     * discrete bucket — 1-30/1-45/1-60/1-90 deliberately overlap/nest, and
     * over_180 is the one floor (>180, unbounded above). Day-0/not-yet-due
     * receivables are excluded from every specific option by design (the
     * business's own spec measures "overdue" starting at 1 day). Expressed
     * as due_date range comparisons via whereDate() (portable across MySQL/
     * SQLite, and this table's own established pattern — see date_from/
     * date_to just above) rather than DATEDIFF()/CURDATE(), which are
     * MySQL-only and break the SQLite-backed test suite.
     */
    private function filteredQuery(array $filters): Builder
    {
        return $this->model->query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
            ->when($filters['invoice_date_from'] ?? null, fn ($query, $date) => $query->whereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->whereDate('invoice_date', '>=', $date)))
            ->when($filters['invoice_date_to'] ?? null, fn ($query, $date) => $query->whereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->whereDate('invoice_date', '<=', $date)))
            // Goods invoices' branch lives on their Sales Order; Transportation invoices have no
            // Sales Order at all, so their own branch_id (captured directly at creation, see
            // InvoiceService::createTransportation()) is the only source for them — match either.
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where(fn ($q) => $q
                ->whereHas('salesOrder', fn ($soQuery) => $soQuery->where('branch_id', $branchId))
                ->orWhere('branch_id', $branchId)))
            ->when($filters['sales_person_id'] ?? null, fn ($query, $salesPersonId) => $query->whereHas('salesOrder', fn ($soQuery) => $soQuery->where('sales_person_id', $salesPersonId)))
            // Sales > Invoices' checkbox-driven print flow (Tanda Terima Invoice / Laporan Penagihan Harian) — resolves checked Invoice ids to their AccountsReceivable rows.
            ->when($filters['invoice_ids'] ?? null, fn ($query, $ids) => $query->whereIn('invoice_id', $ids))
            ->when($filters['aging_bucket'] ?? null, fn ($query, $bucket) => match ($bucket) {
                '30' => $query->whereDate('due_date', '>=', now()->subDays(30))->whereDate('due_date', '<=', now()->subDay()),
                '45' => $query->whereDate('due_date', '>=', now()->subDays(45))->whereDate('due_date', '<=', now()->subDay()),
                '60' => $query->whereDate('due_date', '>=', now()->subDays(60))->whereDate('due_date', '<=', now()->subDay()),
                '90' => $query->whereDate('due_date', '>=', now()->subDays(90))->whereDate('due_date', '<=', now()->subDay()),
                'over_180' => $query->whereDate('due_date', '<', now()->subDays(180)),
                default => $query,
            });
    }

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->with(['customer', 'invoice.termsOfPayment', 'invoice.deliveries', 'salesOrder.salesPerson', 'salesOrder.branch', 'branch', 'delivery'])
            ->latest('due_date')
            ->paginate($perPage);
    }

    /**
     * Same filters as search() but unpaginated — for Export (C2), which must cover every
     * filtered row, not just one page's worth. Also eager-loads customer.termsOfPayment (unlike
     * search()) for the AR Aging report's per-customer "Term" figure.
     */
    public function searchAll(array $filters): Collection
    {
        return $this->filteredQuery($filters)
            ->with(['customer.termsOfPayment', 'invoice.termsOfPayment', 'invoice.deliveries', 'salesOrder.salesPerson', 'salesOrder.branch', 'branch', 'delivery'])
            ->latest('due_date')
            ->get();
    }

    /** AR Detail Report's "Total Outstanding" footer — same filters as search(), never a second definition. */
    public function outstandingTotal(array $filters): float
    {
        return (float) $this->filteredQuery($filters)
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as outstanding')
            ->value('outstanding');
    }

    /**
     * AR Aging report's Summary sheet "Ledger Balance" column — a customer's full outstanding
     * balance across ALL of their receivables, deliberately ignoring the export's active
     * filters/selection (user-confirmed: proven from the real reference export that this figure
     * diverges from the filtered "Total Outstanding" column). One grouped query, not N+1.
     *
     * @return array<string, float> customer_id => balance
     */
    public function ledgerBalanceByCustomerIds(array $customerIds): array
    {
        return $this->model->query()
            ->whereIn('customer_id', array_unique($customerIds))
            ->selectRaw('customer_id, SUM(amount - paid_amount) as balance')
            ->groupBy('customer_id')
            ->pluck('balance', 'customer_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * Per-invoice overdue detail for the Sales Order credit-block check (new
     * Customer Credit feature) — outstandingTotal() gives a sum, this gives
     * the row-level detail ("which invoice, how much, due when") the block
     * message needs. Arithmetic comparison (not a date function), so it's
     * portable to the SQLite test suite same as the rest of this file.
     */
    public function overdueForCustomer(string $customerId): Collection
    {
        return $this->model->query()
            ->where('customer_id', $customerId)
            ->whereDate('due_date', '<', now())
            ->whereRaw('amount - paid_amount > 0')
            ->orderBy('due_date')
            ->get();
    }

    public function outstandingSummary(): array
    {
        $notPaid = AccountsReceivableStatus::PAID->value;

        return [
            'total_outstanding' => (float) $this->model->query()
                ->where('status', '!=', $notPaid)
                ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as outstanding')
                ->value('outstanding'),
            'count' => $this->model->query()->where('status', '!=', $notPaid)->count(),
        ];
    }
}
