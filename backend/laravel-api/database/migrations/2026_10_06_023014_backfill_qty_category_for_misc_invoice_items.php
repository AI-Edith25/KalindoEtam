<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every Transportation line and Misc-matched Goods-import line (item_id null — see
     * InvoiceService::createTransportation()/SalesInvoiceImportService::createGoodsInvoice())
     * was created before those two call sites started setting qty_category, so they sit at NULL.
     * The frontend defaults a NULL/missing qty_category to 'unit' (whole-number), which is what
     * silently blocked decimal Qty edits on these lines. One-shot backfill to 'weight' — same
     * category those call sites now use for every new row — fixes every existing row in a single
     * UPDATE (see [[feedback_status_remap_migrations]]).
     */
    public function up(): void
    {
        DB::table('invoice_items')->whereNull('item_id')->whereNull('qty_category')->update(['qty_category' => 'weight']);
    }

    public function down(): void
    {
        // Data backfill only — not reversible (the original NULL carried no information worth restoring).
    }
};
