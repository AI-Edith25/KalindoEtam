<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Margin tab — Profit = Penjualan (excl. tax) - HPP, sourced only from validated Sales Invoice
 * lines net of Credit Note lines (never Sales Order/Delivery — see the ticket). Built the same way
 * SalesListingRepository nets Credit Notes into one Sales Listing total: a UNION ALL of signed
 * invoice-item and credit-note-item rows, filtered identically to SalesListingRepository (invoice
 * lines by invoice_date, credit note lines by their own credit_note_date) so Margin's Total
 * Penjualan ties out to Sales Listing's net_sales for the same filters. Grouped 3 ways on top of
 * that same union — same "one query, several aggregation modes" shape as ProductSalesRepository's
 * item/item_group toggle — so Per Item/Customer/Invoice profit sums are always identical.
 *
 * HPP (cost_amount) is InvoiceItem's own immutable snapshot (see InvoiceService::createGoods()) —
 * never recomputed here from a live Item cost. A Credit Note line has no cost column of its own;
 * its cost contribution is qty_credited * the original invoice_items.unit_cost.
 *
 * Transportation invoice lines have no item_id (no COGS concept) — they key their own item_key on
 * their own invoice_items.id in Per Item mode rather than merging into one fake "item" bucket, so
 * cross-mode profit totals never diverge because of them.
 */
class MarginRepository
{
    public function paginate(array $filters, string $group, string $sort, string $sortDir, int $perPage): LengthAwarePaginator
    {
        return $this->groupedQuery($filters, $group, $sort, $sortDir)->paginate($perPage);
    }

    /** Every filtered/grouped row, unpaginated — for Export, which must cover the whole filtered set, not one page. */
    public function allGrouped(array $filters, string $group, string $sort, string $sortDir): Collection
    {
        return $this->groupedQuery($filters, $group, $sort, $sortDir)->get();
    }

    /**
     * total_sales/total_cost/total_profit cover every filtered line, including HPP=0 lines (e.g.
     * Transportation). avg_margin_pct excludes HPP=0 lines from both its numerator and denominator
     * — otherwise a Transportation line (cost 0) would read as a 100% margin and skew the average.
     * This exclusion is computed at the raw line grain, independent of which grouping toggle is
     * active — the KPI cards sit above the toggle and follow the active filter, not the toggle.
     *
     * @return array{total_sales: float, total_cost: float, total_profit: float, avg_margin_pct: float}
     */
    public function kpis(array $filters): array
    {
        $totals = $this->flatQuery($filters)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(cost_amount), 0) as total_cost')
            ->first();

        $marginable = $this->flatQuery($filters)
            ->where('cost_amount', '<>', 0)
            ->selectRaw('COALESCE(SUM(amount), 0) as sales')
            ->selectRaw('COALESCE(SUM(cost_amount), 0) as cost')
            ->first();

        $totalSales = (float) $totals->total_sales;
        $totalCost = (float) $totals->total_cost;
        $marginSales = (float) $marginable->sales;
        $marginCost = (float) $marginable->cost;

        return [
            'total_sales' => $totalSales,
            'total_cost' => $totalCost,
            'total_profit' => $totalSales - $totalCost,
            'avg_margin_pct' => $marginSales != 0.0 ? round(($marginSales - $marginCost) / $marginSales * 100, 2) : 0.0,
        ];
    }

    protected function groupedQuery(array $filters, string $group, string $sort, string $sortDir): Builder
    {
        return match ($group) {
            'customer' => $this->customerGroupedQuery($filters, $sort, $sortDir),
            'invoice' => $this->invoiceGroupedQuery($filters, $sort, $sortDir),
            default => $this->itemGroupedQuery($filters, $sort, $sortDir),
        };
    }

    protected function itemGroupedQuery(array $filters, string $sort, string $sortDir): Builder
    {
        $query = $this->flatQuery($filters)
            ->select(['item_key as id', 'item_code', 'item_name'])
            ->selectRaw('SUM(qty) as qty')
            ->addSelect($this->aggregateSelects())
            ->groupBy('item_key', 'item_code', 'item_name');

        $sortColumn = $sort === 'item_name' ? 'item_name' : $this->sortColumn($sort);

        return $query->orderBy($sortColumn, $sortDir);
    }

    protected function customerGroupedQuery(array $filters, string $sort, string $sortDir): Builder
    {
        $query = $this->flatQuery($filters)
            ->select(['customer_id as id', 'customer_code', 'customer_name'])
            ->selectRaw('COUNT(DISTINCT invoice_id) as invoice_count')
            ->addSelect($this->aggregateSelects())
            ->groupBy('customer_id', 'customer_code', 'customer_name');

        $sortColumn = $sort === 'customer_name' ? 'customer_name' : $this->sortColumn($sort);

        return $query->orderBy($sortColumn, $sortDir);
    }

    /**
     * Grouped by invoice_id only (the union carries no header fields beyond identity — see
     * lineUnion()), then joined back to invoices/customers/sales_persons for display. This keeps
     * an invoice's header resolvable even when, for the active date filter, only its Credit Note's
     * line passed (the CN is filtered by its own credit_note_date, same as SalesListingRepository)
     * while the invoice's own line didn't.
     */
    protected function invoiceGroupedQuery(array $filters, string $sort, string $sortDir): Builder
    {
        $grouped = $this->flatQuery($filters)
            ->select(['invoice_id as id'])
            ->addSelect($this->aggregateSelects())
            ->groupBy('invoice_id');

        $query = DB::query()->fromSub($grouped, 'margin_invoices')
            ->join('invoices', 'invoices.id', '=', 'margin_invoices.id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('sales_persons', 'sales_persons.id', '=', 'invoices.sales_person_id')
            ->select([
                'margin_invoices.id',
                'invoices.document_number',
                'invoices.invoice_date as date',
                'customers.customer_name',
            ])
            ->selectRaw("COALESCE(sales_persons.name, 'Unassigned') as sales_person_name")
            ->addSelect(['margin_invoices.amount', 'margin_invoices.cost_amount', 'margin_invoices.profit', 'margin_invoices.margin_pct']);

        $sortColumn = match ($sort) {
            'date' => 'date',
            'document_number' => 'invoices.document_number',
            default => $this->sortColumn($sort),
        };

        return $query->orderBy($sortColumn, $sortDir);
    }

    /** @return array<int, Expression|string> */
    protected function aggregateSelects(): array
    {
        return [
            DB::raw('SUM(amount) as amount'),
            DB::raw('SUM(cost_amount) as cost_amount'),
            DB::raw('SUM(amount) - SUM(cost_amount) as profit'),
            DB::raw('CASE WHEN SUM(amount) = 0 THEN 0 ELSE ROUND((SUM(amount) - SUM(cost_amount)) / SUM(amount) * 100, 2) END as margin_pct'),
        ];
    }

    protected function sortColumn(string $sort): string
    {
        return in_array($sort, ['amount', 'cost_amount', 'profit', 'margin_pct', 'qty'], true) ? $sort : 'profit';
    }

    protected function flatQuery(array $filters): Builder
    {
        return DB::query()->fromSub($this->lineUnion($filters), 'margin_lines');
    }

    protected function lineUnion(array $filters): Builder
    {
        return $this->invoiceLineQuery($filters)->unionAll($this->creditNoteLineQuery($filters));
    }

    /**
     * Column order here must match creditNoteLineQuery() exactly — UNION ALL matches by position.
     * item_key falls back to the invoice_item's own id (never a live Item id) when item_id is null
     * — only true for Transportation lines, which have no shared item identity to group under.
     */
    protected function invoiceLineQuery(array $filters): Builder
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->whereNull('invoice_items.deleted_at')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.status', DocumentStatus::SUBMITTED->value)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('invoices.customer_id', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('invoice_items.item_id', $v))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $v) => $q->where('invoices.sales_person_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(
                fn ($q2) => $q2->where('invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)
            ))
            ->select(['invoices.id as invoice_id'])
            ->selectRaw('COALESCE(invoice_items.item_id, invoice_items.id) as item_key')
            ->addSelect([
                'invoice_items.item_code as item_code',
                'invoice_items.item_name as item_name',
                'customers.id as customer_id',
                'customers.customer_code as customer_code',
                'customers.customer_name as customer_name',
            ])
            ->selectRaw('invoice_items.qty as qty')
            ->selectRaw('invoice_items.amount as amount')
            ->selectRaw('COALESCE(invoice_items.cost_amount, 0) as cost_amount');
    }

    /** Header fields (document_number/date/sales_person) come from the ORIGINAL invoice (cn_invoices), never the Credit Note's own — Per Invoice mode nets a CN into its originating invoice's row, it never gets a row of its own. */
    protected function creditNoteLineQuery(array $filters): Builder
    {
        return DB::table('credit_note_items')
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_items.credit_note_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'credit_note_items.invoice_item_id')
            ->join('invoices as cn_invoices', 'cn_invoices.id', '=', 'credit_notes.invoice_id')
            ->join('customers', 'customers.id', '=', 'credit_notes.customer_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'cn_invoices.sales_order_id')
            ->whereNull('credit_note_items.deleted_at')
            ->whereNull('credit_notes.deleted_at')
            ->where('credit_notes.status', DocumentStatus::SUBMITTED->value)
            ->where('credit_notes.is_reversed', false)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('credit_notes.credit_note_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('credit_notes.credit_note_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('credit_notes.customer_id', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('credit_note_items.item_id', $v))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $v) => $q->where('cn_invoices.sales_person_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(
                fn ($q2) => $q2->where('cn_invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)
            ))
            ->select(['credit_notes.invoice_id as invoice_id'])
            ->selectRaw('credit_note_items.item_id as item_key')
            ->addSelect([
                'credit_note_items.item_code as item_code',
                'credit_note_items.item_name as item_name',
                'customers.id as customer_id',
                'customers.customer_code as customer_code',
                'customers.customer_name as customer_name',
            ])
            ->selectRaw('-credit_note_items.qty_credited as qty')
            ->selectRaw('-credit_note_items.amount as amount')
            ->selectRaw('-COALESCE(credit_note_items.qty_credited * invoice_items.unit_cost, 0) as cost_amount');
    }
}
