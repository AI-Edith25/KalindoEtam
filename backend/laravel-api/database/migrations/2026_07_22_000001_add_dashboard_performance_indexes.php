<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for query patterns introduced by the Sprint 7 dashboard
 * endpoints (outstanding-by-status, today's-total-by-date, low-stock
 * scan) — none of these columns were previously indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_payables', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('order_date');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->index('order_date');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->index('receipt_date');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->index('delivery_date');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->index('current_stock');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_payables', fn (Blueprint $table) => $table->dropIndex(['status']));
        Schema::table('accounts_receivables', fn (Blueprint $table) => $table->dropIndex(['status']));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropIndex(['order_date']));
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropIndex(['order_date']));
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->dropIndex(['receipt_date']));
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropIndex(['delivery_date']));
        Schema::table('items', fn (Blueprint $table) => $table->dropIndex(['current_stock']));
    }
};
