<?php

namespace App\Repositories;

use App\Enums\AccountsReceivableStatus;
use App\Enums\DocumentStatus;
use App\Models\AccountsReceivable;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

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

    /**
     * Kartu Piutang's "Saldo Awal" — the customer's full receivable balance immediately before
     * $beforeDate, computed as 4 scalar SUM()s (never a full-history pull, per the ticket's own
     * performance requirement). Debit sources are Invoice/DebitNote, credit sources are
     * PaymentAllocation/CreditNote — the same 4 sources ledgerRows() below merges for the visible
     * period, just aggregated instead of listed. Only ever called with a real $beforeDate (no
     * period start = no opening balance to compute, see AccountsReceivableService::buildLedger()).
     */
    public function openingBalance(string $customerId, string $beforeDate): float
    {
        $invoiceDebit = Invoice::query()
            ->where('customer_id', $customerId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->whereDate('invoice_date', '<', $beforeDate)
            ->sum('grand_total');

        $debitNoteDebit = DebitNote::query()
            ->where('customer_id', $customerId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->where('is_reversed', false)
            ->whereDate('debit_note_date', '<', $beforeDate)
            ->sum('total_amount');

        $paymentCredit = PaymentAllocation::query()
            ->whereHas('accountsReceivable', fn ($query) => $query->where('customer_id', $customerId))
            ->where('is_reversed', false)
            ->whereDate('allocation_date', '<', $beforeDate)
            ->sum('allocated_amount');

        $creditNoteCredit = CreditNote::query()
            ->where('customer_id', $customerId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->where('is_reversed', false)
            ->whereDate('credit_note_date', '<', $beforeDate)
            ->sum('total_amount');

        return (float) $invoiceDebit + (float) $debitNoteDebit - (float) $paymentCredit - (float) $creditNoteCredit;
    }

    /**
     * Kartu Piutang's transaction stream for one customer — merges the 4 real sources that move
     * an AR balance (there is no single "ledger" table in this schema, see this repository's own
     * class docblock context) into one common row shape, sorted date-then-document-number for a
     * stable order when several transactions share a date. Bounded to one customer, optionally one
     * period — never a cross-customer or full-history pull.
     *
     * document_type is deliberately the exact label frontend/src/features/accounting/lib/
     * journalReferenceLink.ts already switches on ('Invoice'/'Receipt Entry'/'Credit Note'/
     * 'Debit Note') — the frontend reuses that resolver as-is for the clickable document link,
     * no second type-to-route mapping invented for this feature.
     */
    public function ledgerRows(string $customerId, ?string $dateFrom, ?string $dateTo): SupportCollection
    {
        $invoiceRows = Invoice::query()
            ->where('customer_id', $customerId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->when($dateFrom, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
            ->when($dateTo, fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date))
            ->get()
            ->map(fn (Invoice $invoice) => [
                'date' => $invoice->invoice_date->format('Y-m-d'),
                'document_type' => 'Invoice',
                'document_number' => $invoice->document_number,
                'reference_id' => $invoice->id,
                'description' => $invoice->remarks,
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'debit' => (float) $invoice->grand_total,
                'credit' => 0.0,
            ]);

        $paymentRows = PaymentAllocation::query()
            ->whereHas('accountsReceivable', fn ($query) => $query->where('customer_id', $customerId))
            ->where('is_reversed', false)
            ->when($dateFrom, fn ($query, $date) => $query->whereDate('allocation_date', '>=', $date))
            ->when($dateTo, fn ($query, $date) => $query->whereDate('allocation_date', '<=', $date))
            ->with('receiptEntry')
            ->get()
            ->map(fn (PaymentAllocation $allocation) => [
                'date' => $allocation->allocation_date->format('Y-m-d'),
                'document_type' => 'Receipt Entry',
                'document_number' => $allocation->receiptEntry?->document_number,
                'reference_id' => $allocation->receipt_entry_id,
                'description' => $allocation->receiptEntry?->remarks,
                'due_date' => null,
                'debit' => 0.0,
                'credit' => (float) $allocation->allocated_amount,
            ]);

        $creditNoteRows = CreditNote::query()
            ->where('customer_id', $customerId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->where('is_reversed', false)
            ->when($dateFrom, fn ($query, $date) => $query->whereDate('credit_note_date', '>=', $date))
            ->when($dateTo, fn ($query, $date) => $query->whereDate('credit_note_date', '<=', $date))
            ->get()
            ->map(fn (CreditNote $creditNote) => [
                'date' => $creditNote->credit_note_date->format('Y-m-d'),
                'document_type' => 'Credit Note',
                'document_number' => $creditNote->document_number,
                'reference_id' => $creditNote->id,
                'description' => $creditNote->remarks,
                'due_date' => null,
                'debit' => 0.0,
                'credit' => (float) $creditNote->total_amount,
            ]);

        $debitNoteRows = DebitNote::query()
            ->where('customer_id', $customerId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->where('is_reversed', false)
            ->when($dateFrom, fn ($query, $date) => $query->whereDate('debit_note_date', '>=', $date))
            ->when($dateTo, fn ($query, $date) => $query->whereDate('debit_note_date', '<=', $date))
            ->get()
            ->map(fn (DebitNote $debitNote) => [
                'date' => $debitNote->debit_note_date->format('Y-m-d'),
                'document_type' => 'Debit Note',
                'document_number' => $debitNote->document_number,
                'reference_id' => $debitNote->id,
                'description' => $debitNote->remarks,
                'due_date' => null,
                'debit' => (float) $debitNote->total_amount,
                'credit' => 0.0,
            ]);

        return $invoiceRows->concat($paymentRows)->concat($creditNoteRows)->concat($debitNoteRows)
            ->sortBy([['date', 'asc'], ['document_number', 'asc']])
            ->values();
    }

    /**
     * Kartu Piutang's aging summary strip — identical due-date-anchored bucket cutoffs already
     * established in AccountsPayableRepository::groupedBySupplierAgingBuckets() (not_due/1-30/
     * 31-60/61-90/over_90, anchored on now()), applied to this one customer's live outstanding AR
     * rows instead of grouping across all suppliers/customers.
     */
    public function agingSummaryForCustomer(string $customerId): array
    {
        $today = now()->toDateString();
        $minus30 = now()->subDays(30)->toDateString();
        $minus60 = now()->subDays(60)->toDateString();
        $minus90 = now()->subDays(90)->toDateString();
        $outstandingExpr = 'amount - paid_amount';

        $row = $this->model->query()
            ->where('customer_id', $customerId)
            ->selectRaw("COALESCE(SUM(CASE WHEN due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as not_due", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN due_date < ? AND due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_1_30", [$today, $minus30])
            ->selectRaw("COALESCE(SUM(CASE WHEN due_date < ? AND due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_31_60", [$minus30, $minus60])
            ->selectRaw("COALESCE(SUM(CASE WHEN due_date < ? AND due_date >= ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_61_90", [$minus60, $minus90])
            ->selectRaw("COALESCE(SUM(CASE WHEN due_date < ? THEN {$outstandingExpr} ELSE 0 END), 0) as due_over_90", [$minus90])
            ->first();

        return [
            'not_due' => (float) $row->not_due,
            'due_1_30' => (float) $row->due_1_30,
            'due_31_60' => (float) $row->due_31_60,
            'due_61_90' => (float) $row->due_61_90,
            'due_over_90' => (float) $row->due_over_90,
        ];
    }

    /**
     * Kartu Piutang's header Branch/Sales Person — neither is a real Customer attribute in this
     * schema (both live per-transaction, see this repository's own filteredQuery() branch_id
     * comment), so this derives them from the customer's most recent Invoice within the filtered
     * period, falling back to their most recent Invoice overall if none falls in period (a
     * brand-new customer whose only invoice predates the filter, for example). Mirrors Invoice's
     * own branch()/salesPerson() either/or already established for Transportation vs Goods:
     * invoice-level first (the only source for Transportation), Sales Order second (Goods).
     */
    public function latestInvoiceContext(string $customerId, ?string $dateFrom, ?string $dateTo): ?Invoice
    {
        $query = fn () => Invoice::query()
            ->where('customer_id', $customerId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->with(['branch', 'salesPerson', 'salesOrder.branch', 'salesOrder.salesPerson']);

        $invoice = $query()
            ->when($dateFrom, fn ($q, $date) => $q->whereDate('invoice_date', '>=', $date))
            ->when($dateTo, fn ($q, $date) => $q->whereDate('invoice_date', '<=', $date))
            ->latest('invoice_date')
            ->first();

        return $invoice ?? $query()->latest('invoice_date')->first();
    }
}
