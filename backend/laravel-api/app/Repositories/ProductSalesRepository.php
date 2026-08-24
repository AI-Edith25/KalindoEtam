<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\InvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product Sales tab — one row per item (or per Item Group, when $group='item_group'),
 * aggregated over invoice_items/invoices via true DB-level SUM()/GROUP BY (never fetch-all-then-
 * sum-in-PHP), so pagination and the KPI row both reflect the full filtered set, not one page.
 *
 * Branch is resolved the same way JournalEntryRepository::branchMorphConstraint() already does for
 * Invoice: invoices.branch_id is Transportation-only (null for Goods — see Invoice::branch()'s own
 * docblock), so a Goods invoice's branch comes from its anchor Sales Order instead.
 */
class ProductSalesRepository
{
    public function paginate(array $filters, string $group, string $sort, string $sortDir, int $perPage): LengthAwarePaginator
    {
        return $this->groupedQuery($filters, $group, $sort, $sortDir)->paginate($perPage);
    }

    /** Every filtered/grouped row, unpaginated — for Export, which must cover the whole filtered set, not one page. */
    public function allGrouped(array $filters, string $group, string $sort, string $sortDir)
    {
        return $this->groupedQuery($filters, $group, $sort, $sortDir)->get();
    }

    protected function groupedQuery(array $filters, string $group, string $sort, string $sortDir): Builder
    {
        $query = $this->baseQuery($filters);

        if ($group === 'item_group') {
            $query->select([
                'item_groups.id as group_id',
                'item_groups.name as group_name',
                DB::raw('COUNT(DISTINCT invoice_items.item_id) as sku_count'),
                DB::raw('SUM(invoice_items.qty) as qty'),
                DB::raw('SUM(invoice_items.amount) as amount'),
                DB::raw('SUM(invoice_items.tax_amount) as tax_amount'),
            ])->groupBy('item_groups.id', 'item_groups.name');

            $sortColumn = $sort === 'item_name' ? 'group_name' : ($sort === 'qty' ? 'qty' : 'amount');
        } else {
            $query->select([
                'items.id as item_id',
                'items.item_code',
                'items.item_name',
                'item_groups.name as group_name',
                'uoms.name as uom_name',
                DB::raw('SUM(invoice_items.qty) as qty'),
                DB::raw('SUM(invoice_items.amount) as amount'),
                DB::raw('SUM(invoice_items.tax_amount) as tax_amount'),
            ])->groupBy('items.id', 'items.item_code', 'items.item_name', 'item_groups.name', 'uoms.name');

            $sortColumn = $sort === 'item_name' ? 'items.item_name' : ($sort === 'qty' ? 'qty' : 'amount');
        }

        return $query->orderBy($sortColumn, $sortDir);
    }

    /** @return array{total_qty: int, total_revenue: float, total_tax: float, total_incl_tax: float, sku_count: int, top_item_name: ?string, top_item_amount: float} */
    public function kpis(array $filters): array
    {
        $totals = $this->baseQuery($filters)
            ->selectRaw('COALESCE(SUM(invoice_items.qty), 0) as total_qty')
            ->selectRaw('COALESCE(SUM(invoice_items.amount), 0) as total_revenue')
            ->selectRaw('COALESCE(SUM(invoice_items.tax_amount), 0) as total_tax')
            ->selectRaw('COUNT(DISTINCT invoice_items.item_id) as sku_count')
            ->first();

        $top = $this->baseQuery($filters)
            ->select(['items.item_name'])
            ->selectRaw('SUM(invoice_items.amount) as amount')
            ->groupBy('items.id', 'items.item_name')
            ->orderByDesc('amount')
            ->first();

        return [
            'total_qty' => (int) ($totals->total_qty ?? 0),
            'total_revenue' => (float) ($totals->total_revenue ?? 0),
            'total_tax' => (float) ($totals->total_tax ?? 0),
            'total_incl_tax' => (float) ($totals->total_revenue ?? 0) + (float) ($totals->total_tax ?? 0),
            'sku_count' => (int) ($totals->sku_count ?? 0),
            'top_item_name' => $top->item_name ?? null,
            'top_item_amount' => $top ? (float) $top->amount : 0.0,
        ];
    }

    /** Expand-a-row drill-down: which customers bought this item, within the same filter context. @return \Illuminate\Support\Collection */
    public function customersForItem(string $itemId, array $filters)
    {
        return $this->baseQuery(array_merge($filters, ['item_id' => $itemId]))
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select(['customers.id as customer_id', 'customers.customer_code', 'customers.customer_name'])
            ->selectRaw('SUM(invoice_items.qty) as qty')
            ->selectRaw('SUM(invoice_items.amount) as amount')
            ->groupBy('customers.id', 'customers.customer_code', 'customers.customer_name')
            ->orderByDesc('amount')
            ->get();
    }

    protected function baseQuery(array $filters): Builder
    {
        return InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('items', 'items.id', '=', 'invoice_items.item_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->leftJoin('uoms', 'uoms.id', '=', 'items.uom_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.status', $filters['status'] ?? DocumentStatus::SUBMITTED->value)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('invoices.invoice_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('invoices.customer_id', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('invoice_items.item_id', $v))
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $v) => $q->where('invoices.sales_person_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where(
                fn ($q2) => $q2->where('invoices.branch_id', $v)->orWhere('sales_orders.branch_id', $v)
            ))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q2) => $q2->where('items.item_code', 'like', "%{$s}%")->orWhere('items.item_name', 'like', "%{$s}%")
            ));
    }
}
