<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales Listing tab — one row per Invoice OR Credit Note document (never a journal-line-level
 * grain, unlike Journal List's Cash Book export). A Credit Note's amounts are already negated at
 * the SQL level (see creditNoteQuery()), so a plain SUM() across the unioned set nets correctly —
 * "revenue" here means invoiced, not cash received; see Sales Listing's own frontend note.
 *
 * Branch resolves the same way every other Invoice-driven tab does: invoices.branch_id is
 * Transportation-only (null for Goods), so Goods invoices/credit-notes fall back to their Sales
 * Order's branch_id.
 */
class SalesListingRepository
{
    public function paginate(array $filters, string $sort, string $sortDir, int $perPage): LengthAwarePaginator
    {
        return $this->listedQuery($filters)->orderBy($sort, $sortDir)->paginate($perPage);
    }

    /** @return array{net_sales: float, total_tax: float, gross: float, invoice_count: int, paid_value: float, unpaid_value: float} */
    public function kpis(array $filters): array
    {
        $totals = $this->filteredUnion($filters)
            ->selectRaw('COALESCE(SUM(amount), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(tax), 0) as total_tax')
            ->selectRaw('COALESCE(SUM(amount_incl_tax), 0) as gross')
            ->selectRaw("COUNT(CASE WHEN type = 'invoice' THEN 1 END) as invoice_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'invoice' AND payment_status = 'paid' THEN amount_incl_tax ELSE 0 END), 0) as paid_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'invoice' AND payment_status != 'paid' THEN amount_incl_tax ELSE 0 END), 0) as unpaid_value")
            ->first();

        return [
            'net_sales' => (float) $totals->net_sales,
            'total_tax' => (float) $totals->total_tax,
            'gross' => (float) $totals->gross,
            'invoice_count' => (int) $totals->invoice_count,
            'paid_value' => (float) $totals->paid_value,
            'unpaid_value' => (float) $totals->unpaid_value,
        ];
    }

    /** Every filtered row, unpaginated — used by allListed()'s callers needing a Collection (e.g. small ad-hoc reads); Export streams via query() instead. */
    public function allListed(array $filters, string $sort, string $sortDir): Collection
    {
        return $this->listedQuery($filters)->orderBy($sort, $sortDir)->get();
    }

    /** Base query builder (never ->get()) for the streamed Export — the real per-document row count over an unbounded date range can be in the thousands, same posture as Journal List's Cash Book export. */
    public function query(array $filters, string $sort, string $sortDir): Builder
    {
        return $this->listedQuery($filters)->orderBy($sort, $sortDir);
    }

    protected function listedQuery(array $filters): Builder
    {
        return $this->filteredUnion($filters)
            ->select('sales_listing.*')
            ->addSelect('branches.name as branch_name');
    }

    protected function filteredUnion(array $filters): Builder
    {
        $union = $this->invoiceQuery($filters)->unionAll($this->creditNoteQuery($filters));

        return DB::query()->fromSub($union, 'sales_listing')
            ->leftJoin('branches', 'branches.id', '=', 'sales_listing.branch_id')
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('sales_listing.type', $t))
            ->when($filters['payment_status'] ?? null, fn ($q, $s) => $q->where('sales_listing.payment_status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q2) => $q2->where('sales_listing.document_number', 'like', "%{$s}%")->orWhere('sales_listing.customer_name', 'like', "%{$s}%")
            ));
    }

    protected function invoiceQuery(array $filters): Builder
    {
        return DB::table('invoices')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->leftJoin('deliveries', 'deliveries.id', '=', 'invoices.delivery_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('sales_persons', 'sales_persons.id', '=', 'invoices.sales_person_id')
            ->leftJoin('accounts_receivables', 'accounts_receivables.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.status', DocumentStatus::SUBMITTED->value)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('invoices.customer_id', $v))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $v) => $q->where('invoices.sales_person_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(
                fn ($q2) => $q2->where('invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)
            ))
            ->select([
                'invoices.id',
                DB::raw("'invoice' as type"),
                'invoices.document_number',
                'invoices.invoice_date as date',
                'sales_orders.document_number as reference_so',
                'deliveries.document_number as reference_do',
                'customers.customer_code',
                'customers.customer_name',
                'sales_persons.name as sales_person_name',
            ])
            ->selectRaw('COALESCE(invoices.branch_id, sales_orders.branch_id) as branch_id')
            ->addSelect([
                'invoices.subtotal as amount',
                'invoices.discount_amount as discount',
                'invoices.tax_amount as tax',
                'invoices.grand_total as amount_incl_tax',
                'accounts_receivables.status as payment_status',
            ])
            ->selectRaw('accounts_receivables.amount - accounts_receivables.paid_amount as outstanding_ar');
    }

    protected function creditNoteQuery(array $filters): Builder
    {
        return DB::table('credit_notes')
            ->leftJoin('invoices as cn_invoices', 'cn_invoices.id', '=', 'credit_notes.invoice_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'cn_invoices.sales_order_id')
            ->leftJoin('deliveries', 'deliveries.id', '=', 'cn_invoices.delivery_id')
            ->join('customers', 'customers.id', '=', 'credit_notes.customer_id')
            ->leftJoin('sales_persons', 'sales_persons.id', '=', 'cn_invoices.sales_person_id')
            ->whereNull('credit_notes.deleted_at')
            ->where('credit_notes.status', DocumentStatus::SUBMITTED->value)
            ->where('credit_notes.is_reversed', false)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('credit_notes.credit_note_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('credit_notes.credit_note_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('credit_notes.customer_id', $v))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $v) => $q->where('cn_invoices.sales_person_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(
                fn ($q2) => $q2->where('cn_invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)
            ))
            ->select([
                'credit_notes.id',
                DB::raw("'credit_note' as type"),
                'credit_notes.document_number',
                'credit_notes.credit_note_date as date',
                'sales_orders.document_number as reference_so',
                'deliveries.document_number as reference_do',
                'customers.customer_code',
                'customers.customer_name',
                'sales_persons.name as sales_person_name',
            ])
            ->selectRaw('COALESCE(cn_invoices.branch_id, sales_orders.branch_id) as branch_id')
            ->selectRaw('-credit_notes.subtotal as amount')
            ->selectRaw('-credit_notes.discount_amount as discount')
            ->selectRaw('-credit_notes.tax_amount as tax')
            ->selectRaw('-credit_notes.total_amount as amount_incl_tax')
            ->selectRaw('NULL as payment_status')
            ->selectRaw('NULL as outstanding_ar');
    }
}
