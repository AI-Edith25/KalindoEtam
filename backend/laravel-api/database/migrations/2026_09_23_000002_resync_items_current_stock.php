<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: items.current_stock was refreshed from each warehouse's "latest by
 * posting_datetime" ledger row, so any movement posted before a later-dated row
 * (e.g. a Goods Receipt at now() behind a future cutoff_date Opening Stock) never
 * showed up. StockLedgerRepository now uses SUM(qty_change); this resyncs the cache
 * once. Idempotent. No down(): the old values were wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::update(
            'UPDATE items SET current_stock = COALESCE((SELECT SUM(qty_change) FROM stock_ledgers WHERE stock_ledgers.item_id = items.id AND stock_ledgers.deleted_at IS NULL), 0)'
        );

        fwrite(STDOUT, "  Resynced current_stock for {$affected} item(s).\n");
    }

    public function down(): void {}
};
