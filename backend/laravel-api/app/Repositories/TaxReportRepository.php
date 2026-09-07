<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PPN Keluaran / PPN Masukan — one row per document (Sales/Purchase
 * Invoice), never per line, matching how an actual Indonesian PPN ledger
 * is kept. Built as a SQL UNION ALL of already-identically-shaped
 * row-sources so the on-screen list and export share one query, aggregated
 * and paginated in the database per the report's own performance
 * requirement — never loaded into PHP as full Eloquent collections first.
 *
 * Column shape every branch produces: document_id, document_type,
 * document_number, document_date, party_id, party_name, branch_id,
 * warehouse_id, tax_id, tax_code, tax_name, tax_rate, dpp, ppn.
 *
 * Sales Invoice items carry their own tax_id (Goods) or the invoice header
 * does (Transportation) — Purchase Invoice has neither anywhere in its
 * schema, only a manual tax_amount nominal — so inputTaxQuery()'s tax_id/
 * tax_code/tax_rate columns are always null, by design, not a bug.
 *
 * Every join here is a plain column-to-column join (aggregation pushed
 * into a subquery first, COALESCE pushed into the SELECT list, never into
 * a JOIN ON clause) specifically so this stays portable between MySQL and
 * the SQLite-backed test suite — same rule as every other report in this
 * codebase.
 */
class TaxReportRepository
{
    /**
     * A Goods invoice's items can, in principle, mix tax codes; MAX(tax_id)
     * resolves ONE representative code per invoice (if every line shares
     * one, as is the norm, this is exact) while also rolling up DPP/PPN —
     * one row per invoice, not per line.
     */
    private function invoiceItemTotalsSubquery(): Builder
    {
        return DB::table('invoice_items')
            ->select('invoice_id')
            ->selectRaw('MAX(tax_id) as tax_id, SUM(amount) as dpp, SUM(tax_amount) as ppn')
            ->whereNull('deleted_at')
            ->groupBy('invoice_id');
    }

    /**
     * PPN Keluaran — Goods invoices (one row per invoice, via the subquery
     * above) UNION ALL Transportation invoices (header-taxed, already one
     * row each) UNION ALL Credit Notes (reductions — negative dpp/ppn,
     * tax code resolved from the parent invoice the same way).
     */
    public function outputTaxQuery(array $filters): Builder
    {
        $goods = DB::table('invoices')
            ->joinSub($this->invoiceItemTotalsSubquery(), 'item_totals', 'item_totals.invoice_id', '=', 'invoices.id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->leftJoin('taxes', 'taxes.id', '=', 'item_totals.tax_id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.invoice_type', 'goods')
            ->where('invoices.status', 'submitted')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '<=', $d))
            ->when($filters['tax_id'] ?? null, fn ($q, $v) => $q->where('item_totals.tax_id', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('invoices.customer_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(fn ($q2) => $q2->where('invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)))
            ->selectRaw("
                invoices.id as document_id,
                'invoice' as document_type,
                invoices.document_number as document_number,
                invoices.invoice_date as document_date,
                invoices.customer_id as party_id,
                customers.customer_name as party_name,
                COALESCE(invoices.branch_id, sales_orders.branch_id) as branch_id,
                CAST(NULL AS CHAR) as warehouse_id,
                item_totals.tax_id as tax_id,
                taxes.code as tax_code,
                taxes.name as tax_name,
                taxes.rate as tax_rate,
                item_totals.dpp as dpp,
                item_totals.ppn as ppn
            ");

        $transportation = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->leftJoin('taxes', 'taxes.id', '=', 'invoices.tax_id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.invoice_type', 'transportation')
            ->where('invoices.status', 'submitted')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '<=', $d))
            ->when($filters['tax_id'] ?? null, fn ($q, $v) => $q->where('invoices.tax_id', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('invoices.customer_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(fn ($q2) => $q2->where('invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)))
            ->selectRaw("
                invoices.id as document_id,
                'invoice' as document_type,
                invoices.document_number as document_number,
                invoices.invoice_date as document_date,
                invoices.customer_id as party_id,
                customers.customer_name as party_name,
                COALESCE(invoices.branch_id, sales_orders.branch_id) as branch_id,
                CAST(NULL AS CHAR) as warehouse_id,
                invoices.tax_id as tax_id,
                taxes.code as tax_code,
                taxes.name as tax_name,
                taxes.rate as tax_rate,
                invoices.subtotal as dpp,
                invoices.tax_amount as ppn
            ");

        $creditNotes = DB::table('credit_notes')
            ->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->join('customers', 'customers.id', '=', 'credit_notes.customer_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->leftJoinSub($this->invoiceItemTotalsSubquery(), 'item_totals', 'item_totals.invoice_id', '=', 'credit_notes.invoice_id')
            ->leftJoin('taxes as item_tax', 'item_tax.id', '=', 'item_totals.tax_id')
            ->leftJoin('taxes as header_tax', 'header_tax.id', '=', 'invoices.tax_id')
            ->whereNull('credit_notes.deleted_at')
            ->where('credit_notes.status', 'submitted')
            ->where('credit_notes.is_reversed', false)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('credit_notes.credit_note_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('credit_notes.credit_note_date', '<=', $d))
            ->when($filters['tax_id'] ?? null, fn ($q, $v) => $q->where(fn ($q2) => $q2->where('item_totals.tax_id', $v)->orWhere('invoices.tax_id', $v)))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('credit_notes.customer_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(fn ($q2) => $q2->where('invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)))
            ->selectRaw("
                credit_notes.id as document_id,
                'credit_note' as document_type,
                credit_notes.document_number as document_number,
                credit_notes.credit_note_date as document_date,
                credit_notes.customer_id as party_id,
                customers.customer_name as party_name,
                COALESCE(invoices.branch_id, sales_orders.branch_id) as branch_id,
                CAST(NULL AS CHAR) as warehouse_id,
                COALESCE(item_totals.tax_id, invoices.tax_id) as tax_id,
                COALESCE(item_tax.code, header_tax.code) as tax_code,
                COALESCE(item_tax.name, header_tax.name) as tax_name,
                COALESCE(item_tax.rate, header_tax.rate) as tax_rate,
                -credit_notes.subtotal as dpp,
                -credit_notes.tax_amount as ppn
            ");

        return $goods->unionAll($transportation)->unionAll($creditNotes);
    }

    /**
     * PPN Masukan — no tax-code dimension exists anywhere in Purchase
     * Invoice's schema (see class docblock), so both branches are simple,
     * un-grouped, one-row-per-document selects. Warehouse (Purchase's real
     * location dimension — it has no Branch concept at all, same finding
     * as AP Detail) is resolved via the invoice's anchor Goods Receipt.
     */
    public function inputTaxQuery(array $filters): Builder
    {
        $invoices = DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->leftJoin('goods_receipts', 'goods_receipts.id', '=', 'purchase_invoices.goods_receipt_id')
            ->whereNull('purchase_invoices.deleted_at')
            ->where('purchase_invoices.status', 'submitted')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('purchase_invoices.invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('purchase_invoices.invoice_date', '<=', $d))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('purchase_invoices.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.warehouse_id', $v))
            ->selectRaw("
                purchase_invoices.id as document_id,
                'purchase_invoice' as document_type,
                purchase_invoices.document_number as document_number,
                purchase_invoices.invoice_date as document_date,
                purchase_invoices.supplier_id as party_id,
                suppliers.supplier_name as party_name,
                CAST(NULL AS CHAR) as branch_id,
                goods_receipts.warehouse_id as warehouse_id,
                CAST(NULL AS CHAR) as tax_id,
                CAST(NULL AS CHAR) as tax_code,
                CAST(NULL AS CHAR) as tax_name,
                CAST(NULL AS DECIMAL(5,2)) as tax_rate,
                purchase_invoices.subtotal as dpp,
                purchase_invoices.tax_amount as ppn
            ");

        $returns = DB::table('purchase_returns')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_returns.supplier_id')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_returns.purchase_invoice_id')
            ->leftJoin('goods_receipts', 'goods_receipts.id', '=', 'purchase_invoices.goods_receipt_id')
            ->whereNull('purchase_returns.deleted_at')
            ->where('purchase_returns.status', 'submitted')
            ->where('purchase_returns.is_reversed', false)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '<=', $d))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('purchase_returns.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.warehouse_id', $v))
            ->selectRaw("
                purchase_returns.id as document_id,
                'purchase_return' as document_type,
                purchase_returns.document_number as document_number,
                purchase_returns.return_date as document_date,
                purchase_returns.supplier_id as party_id,
                suppliers.supplier_name as party_name,
                CAST(NULL AS CHAR) as branch_id,
                goods_receipts.warehouse_id as warehouse_id,
                CAST(NULL AS CHAR) as tax_id,
                CAST(NULL AS CHAR) as tax_code,
                CAST(NULL AS CHAR) as tax_name,
                CAST(NULL AS DECIMAL(5,2)) as tax_rate,
                -purchase_returns.subtotal as dpp,
                -purchase_returns.tax_amount as ppn
            ");

        return $invoices->unionAll($returns);
    }

    public function paginateOutputTax(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return DB::query()->fromSub($this->outputTaxQuery($filters), 'combined')
            ->orderBy('document_date')
            ->orderBy('document_number')
            ->paginate($perPage);
    }

    public function paginateInputTax(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return DB::query()->fromSub($this->inputTaxQuery($filters), 'combined')
            ->orderBy('document_date')
            ->orderBy('document_number')
            ->paginate($perPage);
    }

    public function allOutputTax(array $filters): Collection
    {
        return DB::query()->fromSub($this->outputTaxQuery($filters), 'combined')
            ->orderBy('document_date')
            ->orderBy('document_number')
            ->get();
    }

    public function allInputTax(array $filters): Collection
    {
        return DB::query()->fromSub($this->inputTaxQuery($filters), 'combined')
            ->orderBy('document_date')
            ->orderBy('document_number')
            ->get();
    }

    /** Summary cards' Total PPN Keluaran/Masukan — same two queries as the lists, summed, never a separately-computed figure. */
    public function outputTaxTotals(array $filters): array
    {
        $row = DB::query()->fromSub($this->outputTaxQuery($filters), 'combined')
            ->selectRaw('COALESCE(SUM(dpp), 0) as dpp, COALESCE(SUM(ppn), 0) as ppn')
            ->first();

        return ['dpp' => (float) $row->dpp, 'ppn' => (float) $row->ppn];
    }

    public function inputTaxTotals(array $filters): array
    {
        $row = DB::query()->fromSub($this->inputTaxQuery($filters), 'combined')
            ->selectRaw('COALESCE(SUM(dpp), 0) as dpp, COALESCE(SUM(ppn), 0) as ppn')
            ->first();

        return ['dpp' => (float) $row->dpp, 'ppn' => (float) $row->ppn];
    }
}
