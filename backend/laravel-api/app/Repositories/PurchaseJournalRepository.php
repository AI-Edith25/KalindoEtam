<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Journal's two screen tabs (Purchase Invoice, Purchase Return) — document-level, one row
 * per document. No existing document-level Purchase report to reuse (PurchaseReportPage's backend
 * is item-aggregate, not document-level), so this is modeled directly on
 * SalesListingRepository's own shape rather than reusing it.
 *
 * Unlike Sales, Purchase has no Branch or Salesman concept anywhere in the schema (confirmed via
 * grep across every migration) — no branch_id/sales_person_id column exists on purchase_invoices
 * or purchase_returns, so there is no Branch filter/column here, unlike Sales Journal.
 */
class PurchaseJournalRepository
{
    public function paginateInvoices(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->invoiceQuery($filters)->orderByDesc('purchase_invoices.invoice_date')->paginate($perPage);
    }

    public function paginateReturns(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->returnQuery($filters)->orderByDesc('purchase_returns.return_date')->paginate($perPage);
    }

    protected function invoiceQuery(array $filters): QueryBuilder
    {
        return DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereNull('purchase_invoices.deleted_at')
            ->where('purchase_invoices.status', DocumentStatus::SUBMITTED->value)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('purchase_invoices.invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('purchase_invoices.invoice_date', '<=', $d))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('purchase_invoices.supplier_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q2) => $q2->where('purchase_invoices.document_number', 'like', "%{$s}%")->orWhere('suppliers.supplier_name', 'like', "%{$s}%")
            ))
            ->select([
                'purchase_invoices.id',
                DB::raw("'purchase_invoice' as type"),
                'purchase_invoices.document_number',
                'purchase_invoices.invoice_date as date',
                'purchase_invoices.reference_number',
                'suppliers.supplier_code',
                'suppliers.supplier_name',
                'purchase_invoices.subtotal as amount',
                'purchase_invoices.tax_amount as tax',
                'purchase_invoices.grand_total as amount_incl_tax',
            ]);
    }

    protected function returnQuery(array $filters): QueryBuilder
    {
        return DB::table('purchase_returns')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_returns.supplier_id')
            ->whereNull('purchase_returns.deleted_at')
            ->where('purchase_returns.status', DocumentStatus::SUBMITTED->value)
            ->where('purchase_returns.is_reversed', false)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '<=', $d))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('purchase_returns.supplier_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q2) => $q2->where('purchase_returns.document_number', 'like', "%{$s}%")->orWhere('suppliers.supplier_name', 'like', "%{$s}%")
            ))
            ->select([
                'purchase_returns.id',
                DB::raw("'purchase_return' as type"),
                'purchase_returns.document_number',
                'purchase_returns.return_date as date',
                DB::raw('NULL as reference_number'), // no reference field of its own — see PurchaseJournalExport for the export's parent-invoice inheritance
                'suppliers.supplier_code',
                'suppliers.supplier_name',
                'purchase_returns.subtotal as amount',
                'purchase_returns.tax_amount as tax',
                'purchase_returns.total_amount as amount_incl_tax',
            ]);
    }

    /** Every filtered Purchase Invoice, items+supplier eager-loaded — a Builder, never ->get(), so PurchaseJournalExport can chunk through it (same reasoning as SalesJournalRepository::exportInvoiceQuery()). */
    public function exportInvoiceQuery(array $filters): Builder
    {
        return PurchaseInvoice::query()
            ->with(['items', 'supplier'])
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::SUBMITTED->value)
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $v) => $q->where('supplier_id', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where('document_number', 'like', "%{$s}%"))
            ->orderBy('invoice_date')
            ->orderBy('document_number');
    }

    /** Every filtered Purchase Return, items+parent Purchase Invoice+supplier eager-loaded — a Builder, never ->get(). */
    public function exportReturnQuery(array $filters): Builder
    {
        return PurchaseReturn::query()
            ->with(['items', 'purchaseInvoice', 'supplier'])
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::SUBMITTED->value)
            ->where('is_reversed', false)
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('return_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('return_date', '<=', $d))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $v) => $q->where('supplier_id', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where('document_number', 'like', "%{$s}%"))
            ->orderBy('return_date')
            ->orderBy('document_number');
    }
}
