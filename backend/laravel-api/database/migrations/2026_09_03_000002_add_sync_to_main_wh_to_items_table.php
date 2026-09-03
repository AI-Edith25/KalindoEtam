<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Samakan dengan Main WH" — a per-item flag (not per item-warehouse row): when set, every
 * non-Main-warehouse price for this item resolves live from the Main warehouse's own resolved
 * price instead of any of its own item_warehouse_prices overrides — see ItemPriceResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('sync_to_main_wh')->default(false)->after('standard_rate');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('sync_to_main_wh');
        });
    }
};
