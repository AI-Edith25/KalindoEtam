<?php

use App\Models\Tax;
use App\Services\TaxService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time historical backfill for goods_receipt_items.tax_id/tax_amount — every existing
     * GR item created against a PO has a real tax on its linked purchase_order_items row, it just
     * predates this column. Recomputed against the GR item's OWN amount (not a verbatim copy of
     * the PO item's tax_amount) since GR qty can differ from PO qty via over-receipt — reuses
     * TaxService::calculate(), this codebase's single source of truth for the inclusive/exclusive
     * rate math, instead of re-deriving it here. Direct Receipts (no purchase_order_item_id) have
     * no PO to backfill from and are left untouched (tax_id null, tax_amount 0 — same as any new
     * Direct Receipt line where the optional manual Tax wasn't set).
     *
     * One SELECT resolves every candidate row via a portable query-builder join (works on both
     * MySQL prod and the sqlite test DB), then a per-row UPDATE applies it — same acceptable
     * one-time cost as the invoice cost-snapshot backfill this mirrors.
     */
    public function up(): void
    {
        $rows = DB::table('goods_receipt_items as gri')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->whereNotNull('gri.purchase_order_item_id')
            ->whereNotNull('poi.tax_id')
            ->select('gri.id', 'gri.amount', 'poi.tax_id')
            ->get();

        $taxService = app(TaxService::class);
        $taxCache = [];

        foreach ($rows as $row) {
            $tax = $taxCache[$row->tax_id] ??= Tax::find($row->tax_id);

            if (! $tax) {
                continue;
            }

            $taxAmount = $taxService->calculate((float) $row->amount, $tax)['tax_amount'];

            DB::table('goods_receipt_items')->where('id', $row->id)->update([
                'tax_id' => $row->tax_id,
                'tax_amount' => $taxAmount,
            ]);
        }
    }

    /** One-way data fix — same posture as the invoice cost-snapshot backfill, nothing meaningful to undo. */
    public function down(): void {}
};
