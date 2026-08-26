<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bulk items (cement) are received by truck-scale weight, e.g. 50.65
     * against a 50 PO — every quantity column downstream of Goods Receipt
     * must carry 2 decimal places, not just whole units. Widens the full
     * chain: PO qty/received_qty -> GR qty -> Invoice qty -> Return
     * qty_returned -> Item.current_stock -> StockLedger (the shared
     * write path for every stock-moving voucher type in the system, Sales
     * included — existing integer callers keep working unchanged against
     * a wider decimal column).
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('qty', 15, 2)->change();
            $table->decimal('received_qty', 15, 2)->default(0)->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('qty', 15, 2)->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('qty', 15, 2)->change();
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->decimal('qty_returned', 15, 2)->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->decimal('current_stock', 15, 2)->default(0)->change();
        });

        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->decimal('qty_change', 15, 2)->change();
            $table->decimal('balance_qty', 15, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->integer('qty_change')->change();
            $table->integer('balance_qty')->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->integer('current_stock')->default(0)->change();
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->integer('qty_returned')->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->integer('qty')->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->integer('qty')->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->integer('qty')->change();
            $table->integer('received_qty')->default(0)->change();
        });
    }
};
