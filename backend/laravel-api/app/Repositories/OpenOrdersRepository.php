<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Open Orders tab — one row per Sales Order line still outstanding.
 *
 * "Outstanding" = qty_ordered - qty_invoiced, never delivered_qty. Qty Delivered is a real, already-
 * maintained stored column (SalesOrderItem::delivered_qty, see SalesOrderItemRepository::
 * incrementDeliveredQty()), but Qty Invoiced has no stored column anywhere — it's the 2-hop chain
 * sales_order_items -> delivery_items (on sales_order_item_id) -> invoice_items (on
 * delivery_item_id), filtered to submitted/non-cancelled invoices, walked here via a correlated
 * subquery per line (one row per line already, no join fan-out to worry about). The Invoice module
 * is complete in this system, so there's no "fall back to delivered_qty" case to handle — Qty
 * Invoiced is always the real answer to "has this line become money yet."
 *
 * qty_outstanding/outstanding_value are computed in SQL (pure arithmetic over the already-resolved
 * qty_invoiced column, portable across MySQL/SQLite) so they can be sorted server-side.
 * age_in_days/overdue are NOT computed in SQL — date-diff functions aren't portable across MySQL
 * (DATEDIFF)/SQLite (julianday), so those are computed in PHP from order_date/
 * expected_delivery_date instead (same reasoning AccountsReceivableService::overdueFigures()
 * already uses); sorting by "age" is sorting by order_date instead, since age is a strict function
 * of it.
 */
class OpenOrdersRepository
{
    public function paginate(array $filters, string $sort, string $sortDir, int $perPage): LengthAwarePaginator
    {
        return $this->outstandingQuery($filters)->orderBy($this->sortColumn($sort), $sortDir)->paginate($perPage);
    }

    /** Every filtered/outstanding row, unpaginated — for Export and for KPI aggregation (see class docblock re: why KPIs aggregate in PHP over this bounded set rather than a portable-SQL aggregate). */
    public function allOutstanding(array $filters, string $sort, string $sortDir): Collection
    {
        return $this->outstandingQuery($filters)->orderBy($this->sortColumn($sort), $sortDir)->get();
    }

    protected function sortColumn(string $sort): string
    {
        return in_array($sort, ['order_date', 'document_number', 'customer_name', 'item_name', 'qty_outstanding', 'outstanding_value'], true)
            ? $sort
            : 'outstanding_value';
    }

    protected function outstandingQuery(array $filters): Builder
    {
        $invoicedQtySubquery = '(
            SELECT COALESCE(SUM(ii.qty), 0)
            FROM invoice_items ii
            INNER JOIN delivery_items di ON di.id = ii.delivery_item_id AND di.deleted_at IS NULL
            INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.deleted_at IS NULL AND inv.status = ?
            WHERE di.sales_order_item_id = sales_order_items.id AND ii.deleted_at IS NULL
        )';

        $inner = SalesOrderItem::query()
            ->select([
                'sales_order_items.id',
                'sales_order_items.qty as qty_ordered',
                'sales_order_items.delivered_qty as qty_delivered',
                'sales_order_items.rate',
                'sales_orders.id as sales_order_id',
                'sales_orders.document_number',
                'sales_orders.status',
                'sales_orders.order_date',
                'sales_orders.expected_delivery_date',
                'customers.customer_name',
                'sales_persons.name as sales_person_name',
                'branches.name as branch_name',
                'items.item_code',
                'items.item_name',
            ])
            ->selectRaw("{$invoicedQtySubquery} as qty_invoiced", [DocumentStatus::SUBMITTED->value])
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('items', 'items.id', '=', 'sales_order_items.item_id')
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->leftJoin('sales_persons', 'sales_persons.id', '=', 'sales_orders.sales_person_id')
            ->leftJoin('branches', 'branches.id', '=', 'sales_orders.branch_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'items.item_group_id')
            ->whereNull('sales_order_items.deleted_at')
            ->whereNull('sales_orders.deleted_at')
            // Cancelled is excluded unconditionally — a further ?status= filter only ever narrows
            // within submitted/approved (see IndexOpenOrdersRequest).
            ->where('sales_orders.status', '!=', SalesOrderStatus::CANCELLED->value)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('sales_orders.status', $s))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('sales_orders.order_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('sales_orders.order_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('sales_orders.customer_id', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('sales_order_items.item_id', $v))
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $v) => $q->where('sales_orders.sales_person_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('sales_orders.branch_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q2) => $q2->where('sales_orders.document_number', 'like', "%{$s}%")->orWhere('customers.customer_name', 'like', "%{$s}%")
            ))
            ->when($filters['aging'] ?? null, fn ($q, $bucket) => $this->applyAging($q, $bucket));

        $query = DB::query()->fromSub($inner, 'open_order_lines')
            ->selectRaw('open_order_lines.*')
            ->selectRaw('qty_ordered - qty_invoiced as qty_outstanding')
            ->selectRaw('(qty_ordered - qty_invoiced) * rate as outstanding_value')
            // "Not yet 100% invoiced/closed" — the one rule that defines a line as still open.
            ->whereColumn('qty_invoiced', '<', 'qty_ordered');

        if ($filters['overdue_only'] ?? null) {
            $query->whereNotNull('expected_delivery_date')->whereDate('expected_delivery_date', '<', Carbon::today()->toDateString());
        }

        return $query;
    }

    /** Ceiling buckets computed from PHP-side date literals — plain date comparisons, no DB-specific date-diff function needed. */
    protected function applyAging($query, string $bucket)
    {
        $today = Carbon::today();

        return match ($bucket) {
            '0-7' => $query->whereDate('sales_orders.order_date', '>=', $today->copy()->subDays(7))->whereDate('sales_orders.order_date', '<=', $today),
            '8-30' => $query->whereDate('sales_orders.order_date', '>=', $today->copy()->subDays(30))->whereDate('sales_orders.order_date', '<', $today->copy()->subDays(7)),
            '31-60' => $query->whereDate('sales_orders.order_date', '>=', $today->copy()->subDays(60))->whereDate('sales_orders.order_date', '<', $today->copy()->subDays(30)),
            'over_60' => $query->whereDate('sales_orders.order_date', '<', $today->copy()->subDays(60)),
            default => $query,
        };
    }
}
