<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cash Book Transaction — document-level read model over receipt_entries and
 * payment_entries directly (not journal_entry_lines; see JournalListRepository
 * for the journal-line-level shape the export uses). One row per Official
 * Receipt or Payment Voucher document, in the same column shape regardless of
 * which view is requested, so the frontend table never has to branch on it.
 *
 * view=all unions both tables so pagination/sorting is correct across the
 * combined set — the only UNION query in this codebase; view=receipt/payment
 * skip the union and just filter one side, but through the exact same
 * column-building/filter code so behavior never diverges by view.
 */
class CashBookRepository
{
    public function paginate(string $view, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = match ($view) {
            'receipt' => $this->receiptQuery(),
            'payment' => $this->paymentQuery(),
            default => $this->receiptQuery()->unionAll($this->paymentQuery()),
        };

        return DB::query()
            ->fromSub($query, 'cash_book')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($q2) => $q2->where('document_number', 'like', "%{$search}%")
                    ->orWhere('party_name', 'like', "%{$search}%")
            ))
            ->orderByDesc('date')
            ->orderByDesc('document_number')
            ->paginate($perPage);
    }

    protected function receiptQuery(): Builder
    {
        return DB::table('receipt_entries')
            ->join('customers', 'customers.id', '=', 'receipt_entries.customer_id')
            ->leftJoin('chart_of_accounts', 'chart_of_accounts.id', '=', 'receipt_entries.cash_account_id')
            ->whereNull('receipt_entries.deleted_at')
            ->select([
                'receipt_entries.id',
                DB::raw("'receipt' as type"),
                'receipt_entries.document_number',
                'customers.customer_name as party_name',
                'chart_of_accounts.name as payment_method_name',
                'receipt_entries.receipt_date as date',
                'receipt_entries.total_amount as debit',
                DB::raw('0 as credit'),
                'receipt_entries.status',
                'receipt_entries.branch_id',
                DB::raw('(receipt_entries.total_amount - receipt_entries.allocated_amount) as unallocated'),
                'receipt_entries.reference_number',
            ]);
    }

    protected function paymentQuery(): Builder
    {
        return DB::table('payment_entries')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'payment_entries.supplier_id')
            ->leftJoin('chart_of_accounts as expense_accounts', 'expense_accounts.id', '=', 'payment_entries.expense_account_id')
            ->leftJoin('chart_of_accounts as cash_accounts', 'cash_accounts.id', '=', 'payment_entries.cash_account_id')
            ->whereNull('payment_entries.deleted_at')
            ->select([
                'payment_entries.id',
                DB::raw("'payment' as type"),
                'payment_entries.document_number',
                DB::raw('COALESCE(suppliers.supplier_name, expense_accounts.name) as party_name'),
                'cash_accounts.name as payment_method_name',
                'payment_entries.payment_date as date',
                DB::raw('0 as debit'),
                'payment_entries.total_amount as credit',
                'payment_entries.status',
                'payment_entries.branch_id',
                // Only meaningful for payment_type=supplier (see PaymentEntry::unallocatedAmount()) —
                // the frontend never renders this column for the Payment Voucher view anyway.
                DB::raw('(payment_entries.total_amount - payment_entries.allocated_amount) as unallocated'),
                'payment_entries.reference_number',
            ]);
    }
}
