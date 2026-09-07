<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Report's By Item tab — one row per item, Qty Dibeli/Nilai Pembelian netted the same
 * "GR minus Return, same union shape" way PurchaseBySupplierRepository does. Price monitoring
 * (Rata-rata/Terakhir/Terendah/Tertinggi) is deliberately GR-only, never netted against Returns —
 * a Return isn't a new price data point, it's the same price being reversed (see the ticket:
 * "Harga = ... pada baris Goods Receipt").
 */
class PurchaseByItemRepository
{
    public function paginate(array $filters, string $sort, string $sortDir, int $perPage): LengthAwarePaginator
    {
        return $this->groupedQuery($filters, $sort, $sortDir)->paginate($perPage);
    }

    /** Every filtered/grouped row, unpaginated — for Export. */
    public function allGrouped(array $filters, string $sort, string $sortDir): Collection
    {
        return $this->groupedQuery($filters, $sort, $sortDir)->get();
    }

    /**
     * One item's GR-item-level transaction history — replaces the legacy "Product Purchase
     * History Price" report. Newest first, unpaginated (a per-item drill-down dialog, not its own
     * paginated view).
     */
    public function history(string $itemId, array $filters): Collection
    {
        return DB::table('goods_receipt_items')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
            ->leftJoin('purchase_order_items', 'purchase_order_items.id', '=', 'goods_receipt_items.purchase_order_item_id')
            ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereNull('goods_receipt_items.deleted_at')
            ->whereNull('goods_receipts.deleted_at')
            ->where('goods_receipts.status', DocumentStatus::SUBMITTED->value)
            ->where('goods_receipt_items.item_id', $itemId)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.warehouse_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '<=', $d))
            ->select([
                'goods_receipts.receipt_date as date',
                'goods_receipts.document_number as gr_number',
                'purchase_orders.document_number as po_number',
                'suppliers.supplier_name as supplier_name',
            ])
            ->selectRaw('goods_receipt_items.qty as qty')
            ->selectRaw('goods_receipt_items.rate as rate')
            ->selectRaw('goods_receipt_items.amount as amount')
            ->orderByDesc('goods_receipts.receipt_date')
            ->orderByDesc('goods_receipt_items.id')
            ->get();
    }

    protected function groupedQuery(array $filters, string $sort, string $sortDir): Builder
    {
        $netted = $this->flatQuery($filters)
            ->select(['item_id as id', 'item_code', 'item_name'])
            ->selectRaw('MAX(uom) as uom')
            ->selectRaw('SUM(qty) as qty')
            ->selectRaw('SUM(amount) as amount')
            ->groupBy('item_id', 'item_code', 'item_name');

        $query = DB::query()->fromSub($netted, 'netted')
            ->leftJoinSub($this->priceStatsQuery($filters), 'stats', 'stats.item_id', '=', 'netted.id')
            ->select(['netted.*', 'stats.avg_price', 'stats.lowest_price', 'stats.highest_price', 'stats.last_price']);

        $sortable = ['amount', 'qty', 'item_name', 'avg_price', 'last_price', 'lowest_price', 'highest_price'];
        $sortColumn = in_array($sort, $sortable, true) ? $sort : 'amount';

        return $query->orderBy($sortColumn, $sortDir);
    }

    protected function flatQuery(array $filters): Builder
    {
        return DB::query()->fromSub($this->lineUnion($filters), 'purchase_lines');
    }

    protected function lineUnion(array $filters): Builder
    {
        return $this->goodsReceiptLineQuery($filters)->unionAll($this->returnLineQuery($filters));
    }

    /** Column order here must match returnLineQuery() exactly — UNION ALL matches by position. */
    protected function goodsReceiptLineQuery(array $filters): Builder
    {
        return DB::table('goods_receipt_items')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->whereNull('goods_receipt_items.deleted_at')
            ->whereNull('goods_receipts.deleted_at')
            ->where('goods_receipts.status', DocumentStatus::SUBMITTED->value)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.warehouse_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '<=', $d))
            ->select([
                'goods_receipt_items.item_id as item_id',
                'goods_receipt_items.item_code as item_code',
                'goods_receipt_items.item_name as item_name',
                'goods_receipt_items.uom as uom',
            ])
            ->selectRaw('goods_receipt_items.qty as qty')
            ->selectRaw('goods_receipt_items.amount as amount');
    }

    protected function returnLineQuery(array $filters): Builder
    {
        return DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->whereNull('purchase_return_items.deleted_at')
            ->whereNull('purchase_returns.deleted_at')
            ->where('purchase_returns.status', DocumentStatus::SUBMITTED->value)
            ->where('purchase_returns.is_reversed', false)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('purchase_returns.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('purchase_return_items.warehouse_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '<=', $d))
            ->select([
                'purchase_return_items.item_id as item_id',
                'purchase_return_items.item_code as item_code',
                'purchase_return_items.item_name as item_name',
                'purchase_return_items.uom as uom',
            ])
            ->selectRaw('-purchase_return_items.qty_returned as qty')
            ->selectRaw('-purchase_return_items.amount as amount');
    }

    /**
     * GR-only, grouped by item: weighted average price, min/max rate, and "last price" (the rate
     * of the most recent receipt_date row, tie-broken by id) via ROW_NUMBER() — filtered to rn=1
     * one level up, since a window function's own alias can't be referenced in the same SELECT's
     * WHERE. Null-safe (left-joined onto the netted totals) for an item whose only in-period
     * activity is a Return with no matching in-period Goods Receipt.
     */
    protected function priceStatsQuery(array $filters): Builder
    {
        $base = DB::table('goods_receipt_items')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->whereNull('goods_receipt_items.deleted_at')
            ->whereNull('goods_receipts.deleted_at')
            ->where('goods_receipts.status', DocumentStatus::SUBMITTED->value)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.warehouse_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '<=', $d))
            ->select([
                'goods_receipt_items.id',
                'goods_receipt_items.item_id',
                'goods_receipt_items.rate',
                'goods_receipt_items.qty',
                'goods_receipt_items.amount',
                'goods_receipts.receipt_date',
            ]);

        $ranked = DB::query()->fromSub($base, 'gr')
            ->select('gr.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY gr.item_id ORDER BY gr.receipt_date DESC, gr.id DESC) as rn');

        $last = DB::query()->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->select(['item_id', 'rate as last_price']);

        return DB::query()->fromSub($base, 'gr_agg')
            ->select('gr_agg.item_id')
            // "* 1.0" forces float division — SQLite otherwise does integer division when both
            // SUM()s happen to be whole numbers (its NUMERIC column affinity stores a
            // no-remainder REAL as an INTEGER), silently truncating the average.
            ->selectRaw('(SUM(gr_agg.amount) * 1.0) / NULLIF(SUM(gr_agg.qty), 0) as avg_price')
            ->selectRaw('MIN(gr_agg.rate) as lowest_price')
            ->selectRaw('MAX(gr_agg.rate) as highest_price')
            ->groupBy('gr_agg.item_id')
            ->leftJoinSub($last, 'last', 'last.item_id', '=', 'gr_agg.item_id')
            ->addSelect('last.last_price');
    }
}
