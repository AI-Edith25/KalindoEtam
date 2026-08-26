<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standalone/direct Goods Receipts (no source Purchase Order) — the FK
     * stays, only the NOT NULL constraint drops. Same safe nullable-FK
     * pattern as 2026_08_10_000001_make_invoice_delivery_columns_nullable_for_transportation.
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->uuid('purchase_order_id')->nullable()->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('purchase_order_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('purchase_order_item_id')->nullable(false)->change();
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->uuid('purchase_order_id')->nullable(false)->change();
        });
    }
};
