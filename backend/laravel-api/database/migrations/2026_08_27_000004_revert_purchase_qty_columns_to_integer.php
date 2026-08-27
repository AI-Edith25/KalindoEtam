<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverts 2026_08_27_000001 — that migration widened qty to decimal on
     * the false premise that "Receive Now" is a truck-scale weight. It
     * isn't: qty is a count of units (zak/lot/unit), the value paid and
     * checked against the PO. Weight is tracked separately (see the
     * goods_receipt_items weight columns migration) and never touches qty.
     *
     * Rounds before narrowing the column (not a bare ->change()) so any
     * fractional qty entered for real between the two deploys is rounded,
     * not silently truncated by the ALTER.
     */
    public function up(): void
    {
        DB::table('purchase_order_items')->update(['qty' => DB::raw('ROUND(qty)'), 'received_qty' => DB::raw('ROUND(received_qty)')]);
        DB::table('goods_receipt_items')->update(['qty' => DB::raw('ROUND(qty)')]);
        DB::table('purchase_invoice_items')->update(['qty' => DB::raw('ROUND(qty)')]);
        DB::table('purchase_return_items')->update(['qty_returned' => DB::raw('ROUND(qty_returned)')]);
        DB::table('items')->update(['current_stock' => DB::raw('ROUND(current_stock)')]);
        DB::table('stock_ledgers')->update(['qty_change' => DB::raw('ROUND(qty_change)'), 'balance_qty' => DB::raw('ROUND(balance_qty)')]);

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->integer('qty')->change();
            $table->integer('received_qty')->default(0)->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->integer('qty')->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->integer('qty')->change();
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->integer('qty_returned')->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->integer('current_stock')->default(0)->change();
        });

        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->integer('qty_change')->change();
            $table->integer('balance_qty')->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->decimal('qty_change', 15, 2)->change();
            $table->decimal('balance_qty', 15, 2)->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->decimal('current_stock', 15, 2)->default(0)->change();
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->decimal('qty_returned', 15, 2)->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('qty', 15, 2)->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('qty', 15, 2)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('qty', 15, 2)->change();
            $table->decimal('received_qty', 15, 2)->default(0)->change();
        });
    }
};
