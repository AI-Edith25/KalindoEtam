<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standalone/direct Deliveries (no source Sales Order) — the FK stays,
     * only the NOT NULL constraint drops. Same safe nullable-FK pattern as
     * 2026_08_27_000003_make_goods_receipt_purchase_order_nullable.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->uuid('sales_order_id')->nullable()->change();
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->uuid('sales_order_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->uuid('sales_order_item_id')->nullable(false)->change();
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->uuid('sales_order_id')->nullable(false)->change();
        });
    }
};
