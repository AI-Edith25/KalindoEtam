<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widens every qty column to DECIMAL(18,4) — storage is decimal
     * end to end; whether a given line must actually be a whole number is
     * enforced in the app layer by App\Services\QtyCategoryValidator based
     * on the line's Item.qty_category (see that Item migration), not by the
     * column type. Also snapshots qty_category onto each line-item table,
     * same historical-accuracy rationale as their existing `uom` string
     * snapshot ("must not rely solely on the live Item relation").
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->decimal('received_qty', 18, 4)->default(0)->change();
            $table->string('qty_category')->nullable()->after('qty');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('qty');
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('qty');
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->decimal('qty_returned', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('qty_returned');
        });

        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->decimal('system_qty', 18, 4)->change();
            $table->decimal('counted_qty', 18, 4)->change();
            $table->decimal('difference_qty', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('difference_qty');
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('qty');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->decimal('current_stock', 18, 4)->default(0)->change();
        });

        Schema::table('stock_ledgers', function (Blueprint $table) {
            $table->decimal('qty_change', 18, 4)->change();
            $table->decimal('balance_qty', 18, 4)->change();
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

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty')->change();
        });

        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('system_qty')->change();
            $table->integer('counted_qty')->change();
            $table->integer('difference_qty')->change();
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty_returned')->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty')->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty')->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty')->change();
            $table->integer('received_qty')->default(0)->change();
        });
    }
};
