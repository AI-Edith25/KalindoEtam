<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Report's PO Tracking tab — which submitted Purchase Orders haven't fully arrived yet.
 * received_qty is read directly off purchase_order_items (PurchaseOrderItemRepository::
 * incrementReceivedQty() already accumulates it correctly across every Goods Receipt submitted
 * against that PO item — see GoodsReceiptService::submit()), never re-derived via a join over
 * goods_receipt_items — so a PO received across several partial Goods Receipts sums correctly.
 *
 * Draft/Cancelled POs are excluded (status must be SUBMITTED) — there's no separate "Approved"
 * status in this app (DocumentStatus is draft/submitted/cancelled only; approval happens via
 * ApprovalFlow before submit() flips status).
 */
class PoTrackingRepository
{
    public function paginate(array $filters, string $sort, string $sortDir, int $perPage): LengthAwarePaginator
    {
        return $this->filteredQuery($filters, $sort, $sortDir)->paginate($perPage);
    }

    /** Every filtered row, unpaginated — for Export. */
    public function allFiltered(array $filters, string $sort, string $sortDir): Collection
    {
        return $this->filteredQuery($filters, $sort, $sortDir)->get();
    }

    /** Per-item breakdown for one PO's drill-down dialog — real, manually-created POs only. */
    public function items(string $purchaseOrderId): Collection
    {
        return DB::table('purchase_order_items')
            ->join('items', 'items.id', '=', 'purchase_order_items.item_id')
            ->where('purchase_order_items.purchase_order_id', $purchaseOrderId)
            ->whereNull('purchase_order_items.deleted_at')
            ->select(['items.item_name'])
            ->selectRaw('purchase_order_items.qty as ordered_qty')
            ->selectRaw('purchase_order_items.received_qty as received_qty')
            ->selectRaw('purchase_order_items.qty - purchase_order_items.received_qty as remaining_qty')
            ->get();
    }

    /**
     * Non-null only for a PO fabricated by the Purchase History import — its single placeholder
     * line item is never a real breakdown to show, so the drill-down dialog swaps to this instead
     * (see PoTrackingService::items()).
     */
    public function importMeta(string $purchaseOrderId): ?object
    {
        return DB::table('purchase_orders')
            ->where('id', $purchaseOrderId)
            ->whereNotNull('import_source_type')
            ->select(['import_source_type', 'import_extra'])
            ->first();
    }

    /**
     * Filters on receiving_status/incomplete_only happen one level above the fields' own
     * definition (aggregatedQuery -> computed columns -> this outer filter) — a derived column
     * can't be referenced in a WHERE at the same SELECT level it's computed in.
     *
     * A row whose PO came from the Purchase History import (import_source_type IS NOT NULL)
     * carries a fabricated single-placeholder-item qty (see PurchaseHistoryImportService) — its
     * qty-based columns are forced to NULL here rather than computed off that fake qty, since it
     * was never a real "ordered N, received N" fact. po_tracking_amount rows get a value-based
     * fulfillment_pct instead (billed / total, straight from the source file); historical_invoice
     * rows have no such figure in their source file either, so it stays NULL too.
     */
    protected function filteredQuery(array $filters, string $sort, string $sortDir): Builder
    {
        $computed = DB::query()->fromSub($this->aggregatedQuery($filters), 'po')
            ->select([
                'po.id', 'po.document_number', 'po.order_date', 'po.expected_delivery_date', 'po.total_amount',
                'po.supplier_name', 'po.import_source_type', 'po.amount_billed', 'po.outstanding_grn_value', 'po.outstanding_po_value',
            ])
            // A row fabricated by the Purchase History import (import_source_type IS NOT NULL)
            // carries a fake single-placeholder-item qty — every qty-based column below is forced
            // to NULL for it rather than computed off that fake qty, since it was never a real
            // "ordered N, received N" fact.
            ->selectRaw('CASE WHEN po.import_source_type IS NOT NULL THEN NULL ELSE po.ordered_qty END as ordered_qty')
            ->selectRaw('CASE WHEN po.import_source_type IS NOT NULL THEN NULL ELSE po.received_qty END as received_qty')
            ->selectRaw('CASE WHEN po.import_source_type IS NOT NULL THEN NULL ELSE po.ordered_qty - po.received_qty END as remaining_qty')
            // "* 1.0" forces float division — see PurchaseByItemRepository's avg_price for why.
            ->selectRaw('CASE WHEN po.import_source_type IS NOT NULL THEN NULL WHEN po.ordered_qty = 0 THEN 0 ELSE ROUND((po.received_qty * 1.0) / po.ordered_qty * 100, 2) END as fulfillment_pct')
            ->selectRaw("CASE WHEN po.import_source_type IS NOT NULL THEN NULL WHEN po.received_qty <= 0 THEN 'not_received' WHEN po.received_qty >= po.ordered_qty THEN 'complete' ELSE 'partial' END as receiving_status")
            ->selectRaw(
                'CASE WHEN po.import_source_type IS NULL AND po.expected_delivery_date IS NOT NULL AND po.expected_delivery_date < ? AND po.received_qty < po.ordered_qty THEN 1 ELSE 0 END as is_overdue',
                [now()->toDateString()]
            )
            // Value-based fulfillment for po_tracking_amount rows only — straight from the source
            // file's own AMOUNT BILLED / AMOUNT (no qty involved at all). historical_invoice rows
            // have no such figure in their source file either, so this stays NULL for them too.
            ->selectRaw("CASE WHEN po.import_source_type = 'po_tracking_amount' AND po.total_amount > 0 THEN ROUND((po.amount_billed * 1.0) / po.total_amount * 100, 2) ELSE NULL END as fulfillment_pct_value");

        // filter_var(..., FILTER_VALIDATE_BOOLEAN), not empty()/truthiness — incomplete_only
        // arrives as the string "false" from axios (see IndexPoTrackingRequest), and PHP's
        // empty("false") is false (a non-"0" non-empty string), so !empty() would treat the
        // string "false" as true.
        $incompleteOnly = filter_var($filters['incomplete_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $wrapped = DB::query()->fromSub($computed, 'tracked')
            ->when($filters['receiving_status'] ?? null, fn ($q, $v) => $q->where('receiving_status', $v))
            // An imported row (import_source_type IS NOT NULL) has no qty-based receiving_status
            // at all (NULL) — "belum lengkap" has no meaning for it, so it's never hidden by this
            // toggle rather than being silently excluded by a NULL < 100 comparison.
            ->when($incompleteOnly, fn ($q) => $q->where(fn ($sub) => $sub->whereNull('fulfillment_pct')->orWhere('fulfillment_pct', '<', 100)));

        $sortable = ['order_date', 'total_amount', 'ordered_qty', 'received_qty', 'remaining_qty', 'fulfillment_pct', 'document_number'];
        $sortColumn = in_array($sort, $sortable, true) ? $sort : 'order_date';

        return $wrapped->orderBy($sortColumn, $sortDir);
    }

    protected function aggregatedQuery(array $filters): Builder
    {
        return DB::table('purchase_orders')
            ->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('purchase_order_items.deleted_at')
            ->where('purchase_orders.status', DocumentStatus::SUBMITTED->value)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('purchase_orders.supplier_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('purchase_orders.order_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('purchase_orders.order_date', '<=', $d))
            // A PO carries no warehouse of its own — a warehouse is only known once something is
            // received against it, so this only ever matches a PO that already has a receipt into
            // that warehouse (a PO with zero receipts so far can't match any warehouse filter).
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->whereExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('goods_receipts')
                    ->whereColumn('goods_receipts.purchase_order_id', 'purchase_orders.id')
                    ->where('goods_receipts.warehouse_id', $v)
            ))
            ->groupBy(
                'purchase_orders.id', 'purchase_orders.document_number', 'purchase_orders.order_date',
                'purchase_orders.expected_delivery_date', 'purchase_orders.total_amount', 'suppliers.supplier_name',
                'purchase_orders.import_source_type', 'purchase_orders.amount_billed',
                'purchase_orders.outstanding_grn_value', 'purchase_orders.outstanding_po_value',
            )
            ->select([
                'purchase_orders.id',
                'purchase_orders.document_number',
                'purchase_orders.order_date',
                'purchase_orders.expected_delivery_date',
                'purchase_orders.total_amount',
                'suppliers.supplier_name',
                'purchase_orders.import_source_type',
                'purchase_orders.amount_billed',
                'purchase_orders.outstanding_grn_value',
                'purchase_orders.outstanding_po_value',
            ])
            ->selectRaw('SUM(purchase_order_items.qty) as ordered_qty')
            ->selectRaw('SUM(purchase_order_items.received_qty) as received_qty');
    }
}
