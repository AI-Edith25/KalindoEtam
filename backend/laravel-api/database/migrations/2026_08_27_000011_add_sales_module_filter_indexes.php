<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the Sales module's new advanced filters/bulk export — status
 * multi-select and date-range filters now run on every list AND every
 * unpaginated export query. Mirrors 2026_07_22_000001's pattern (which
 * already indexed sales_orders.order_date/deliveries.delivery_date but not
 * status on any of the 5 tables, nor the other 3 date columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index('status');
            $table->index('invoice_date');
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->index('status');
            $table->index('credit_note_date');
        });

        Schema::table('debit_notes', function (Blueprint $table) {
            $table->index('status');
            $table->index('debit_note_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropIndex(['status']));
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropIndex(['status']));
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['invoice_date']);
        });
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['credit_note_date']);
        });
        Schema::table('debit_notes', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['debit_note_date']);
        });
    }
};
