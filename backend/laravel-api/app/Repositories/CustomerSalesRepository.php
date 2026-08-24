<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Customer Sales tab — one row per customer, aggregated over invoice_items/invoices via true
 * DB-level SUM()/GROUP BY, same posture as ProductSalesRepository (see its own docblock for why —
 * KPIs must reflect the full filtered set, not one page).
 *
 * Branch and Sales Person are both potentially inconsistent across a customer's invoices (a
 * customer can buy from more than one branch, or be served by more than one sales person) — each
 * column shows the single value when every matching invoice agrees, else null ("Multiple" on the
 * frontend), the same "blank when mixed" rule SalesReportService::headerTaxCode() already uses for
 * an analogous per-invoice-line inconsistency.
 */
class CustomerSalesRepository
{
    public function paginate(array $filters, string $sort, string $sortDir, int $perPage): LengthAwarePaginator
    {
        return $this->groupedQuery($filters, $sort, $sortDir)->paginate($perPage);
    }

    /** @return array{total_customers: int, total_revenue: float, total_tax: float, total_incl_tax: float, avg_per_customer: float, top_customer_name: ?string, top_customer_amount: float} */
    public function kpis(array $filters): array
    {
        $totals = $this->baseQuery($filters)
            ->selectRaw('COALESCE(SUM(invoice_items.amount), 0) as total_revenue')
            ->selectRaw('COALESCE(SUM(invoice_items.tax_amount), 0) as total_tax')
            ->selectRaw('COUNT(DISTINCT invoices.customer_id) as total_customers')
            ->first();

        $top = $this->baseQuery($filters)
            ->select(['customers.customer_name'])
            ->selectRaw('SUM(invoice_items.amount) as amount')
            ->groupBy('customers.id', 'customers.customer_name')
            ->orderByDesc('amount')
            ->first();

        $totalCustomers = (int) ($totals->total_customers ?? 0);
        $totalRevenue = (float) ($totals->total_revenue ?? 0);

        return [
            'total_customers' => $totalCustomers,
            'total_revenue' => $totalRevenue,
            'total_tax' => (float) ($totals->total_tax ?? 0),
            'total_incl_tax' => $totalRevenue + (float) ($totals->total_tax ?? 0),
            'avg_per_customer' => $totalCustomers > 0 ? $totalRevenue / $totalCustomers : 0.0,
            'top_customer_name' => $top->customer_name ?? null,
            'top_customer_amount' => $top ? (float) $top->amount : 0.0,
        ];
    }

    /** Every filtered/grouped row, unpaginated — for Export. */
    public function allGrouped(array $filters, string $sort, string $sortDir): Collection
    {
        return $this->groupedQuery($filters, $sort, $sortDir)->get();
    }

    /** Expand-a-row drill-down: every document for this customer within the same filter context, plus a subtotal. */
    public function documentsForCustomer(string $customerId, array $filters): Collection
    {
        return Invoice::query()
            ->with('salesOrder')
            ->whereNull('deleted_at')
            ->where('customer_id', $customerId)
            ->where('status', $filters['status'] ?? DocumentStatus::SUBMITTED->value)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->orderBy('invoice_date')
            ->get();
    }

    protected function groupedQuery(array $filters, string $sort, string $sortDir): Builder
    {
        $sortColumn = match ($sort) {
            'qty' => 'qty',
            'customer_name' => 'customers.customer_name',
            'transaction_count' => 'transaction_count',
            default => 'amount',
        };

        return $this->baseQuery($filters)
            ->select([
                'customers.id as customer_id',
                'customers.customer_code',
                'customers.customer_name',
            ])
            ->selectRaw('COUNT(DISTINCT invoice_items.invoice_id) as transaction_count')
            ->selectRaw('SUM(invoice_items.qty) as qty')
            ->selectRaw('SUM(invoice_items.amount) as amount')
            ->selectRaw('SUM(invoice_items.tax_amount) as tax_amount')
            ->selectRaw('MAX(invoices.invoice_date) as last_transaction_date')
            ->selectRaw('CASE WHEN COUNT(DISTINCT invoices.sales_person_id) = 1 THEN MAX(sales_persons.name) ELSE NULL END as sales_person_name')
            ->selectRaw('CASE WHEN COUNT(DISTINCT COALESCE(invoices.branch_id, sales_orders.branch_id)) = 1 THEN MAX(COALESCE(branches.name, so_branches.name)) ELSE NULL END as branch_name')
            ->groupBy('customers.id', 'customers.customer_code', 'customers.customer_name')
            ->orderBy($sortColumn, $sortDir);
    }

    protected function baseQuery(array $filters): Builder
    {
        return InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('items', 'items.id', '=', 'invoice_items.item_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->leftJoin('sales_persons', 'sales_persons.id', '=', 'invoices.sales_person_id')
            ->leftJoin('branches', 'branches.id', '=', 'invoices.branch_id')
            ->leftJoin('branches as so_branches', 'so_branches.id', '=', 'sales_orders.branch_id')
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
                fn ($q2) => $q2->where('customers.customer_code', 'like', "%{$s}%")->orWhere('customers.customer_name', 'like', "%{$s}%")
            ));
    }

    /** Sales Achievement by Sales Person panel — Invoice-driven (not Sales Order), see the Sales Report rework plan's "Assumptions" §6. */
    public function achievement(array $filters): Collection
    {
        return $this->baseQuery($filters)
            ->select(['invoices.sales_person_id'])
            ->selectRaw("COALESCE(sales_persons.name, 'Unassigned') as sales_person_name")
            ->selectRaw('SUM(invoice_items.qty) as qty')
            ->selectRaw('SUM(invoice_items.amount) as amount')
            ->groupBy('invoices.sales_person_id', 'sales_persons.name')
            ->orderByDesc('amount')
            ->get();
    }
}
