<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Report's By Supplier tab — one row per supplier, sourced from Goods Receipt (what was
 * actually received), never Purchase Order (what was merely ordered). Built the same way
 * SalesListingRepository nets Credit Notes into one Sales Listing total: a UNION ALL of signed
 * goods-receipt-item and purchase-return-item rows (Return already carries its own supplier_id/
 * warehouse_id/item fields — no join needed back through Purchase Invoice/Goods Receipt to net
 * it), grouped by supplier on top.
 *
 * Default sort is Nilai Pembelian desc, which the ticket explicitly notes doubles as "Top
 * Supplier" — no separate ranking page.
 */
class PurchaseBySupplierRepository
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

    /** @return array{total_purchases: float, active_supplier_count: int, top_supplier_name: ?string, top_supplier_amount: float} */
    public function kpis(array $filters): array
    {
        $totals = $this->flatQuery($filters)->selectRaw('COALESCE(SUM(amount), 0) as total_purchases')->first();

        // "Active" = actually received something this period — Goods Receipt side only, a
        // supplier who only appears via a stray Return isn't meaningfully "active" here.
        $activeSuppliers = (int) $this->goodsReceiptLineQuery($filters)->distinct()->count('supplier_id');

        $top = $this->groupedQuery($filters, 'amount', 'desc')->first();

        return [
            'total_purchases' => (float) $totals->total_purchases,
            'active_supplier_count' => $activeSuppliers,
            'top_supplier_name' => $top->supplier_name ?? null,
            'top_supplier_amount' => $top ? (float) $top->amount : 0.0,
        ];
    }

    protected function groupedQuery(array $filters, string $sort, string $sortDir): Builder
    {
        $query = $this->flatQuery($filters)
            ->select(['supplier_id as id', 'supplier_code', 'supplier_name'])
            ->selectRaw('COUNT(DISTINCT document_id) as receipt_count')
            ->selectRaw('SUM(qty) as qty')
            ->selectRaw('SUM(amount) as amount')
            ->groupBy('supplier_id', 'supplier_code', 'supplier_name');

        $sortColumn = in_array($sort, ['amount', 'qty', 'receipt_count', 'supplier_name'], true) ? $sort : 'amount';

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
            ->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
            ->whereNull('goods_receipt_items.deleted_at')
            ->whereNull('goods_receipts.deleted_at')
            ->where('goods_receipts.status', DocumentStatus::SUBMITTED->value)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('goods_receipts.warehouse_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '<=', $d))
            ->select([
                'suppliers.id as supplier_id',
                'suppliers.supplier_code as supplier_code',
                'suppliers.supplier_name as supplier_name',
                'goods_receipts.id as document_id',
            ])
            ->selectRaw('goods_receipt_items.qty as qty')
            ->selectRaw('goods_receipt_items.amount as amount');
    }

    /**
     * document_id is NULL here (a Return isn't a "penerimaan") — COUNT(DISTINCT document_id) in
     * groupedQuery() ignores NULLs, so this side never inflates "Jml Penerimaan".
     */
    protected function returnLineQuery(array $filters): Builder
    {
        return DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_returns.supplier_id')
            ->whereNull('purchase_return_items.deleted_at')
            ->whereNull('purchase_returns.deleted_at')
            ->where('purchase_returns.status', DocumentStatus::SUBMITTED->value)
            ->where('purchase_returns.is_reversed', false)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('purchase_returns.supplier_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('purchase_return_items.warehouse_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '<=', $d))
            ->select([
                'suppliers.id as supplier_id',
                'suppliers.supplier_code as supplier_code',
                'suppliers.supplier_name as supplier_name',
            ])
            ->selectRaw('NULL as document_id')
            ->selectRaw('-purchase_return_items.qty_returned as qty')
            ->selectRaw('-purchase_return_items.amount as amount');
    }
}
