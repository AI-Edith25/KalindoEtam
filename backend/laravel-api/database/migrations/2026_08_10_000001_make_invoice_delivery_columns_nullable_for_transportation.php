<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transportation invoices no longer require a Sales Order or Delivery —
     * a Customer is picked directly and line items are typed manually. These
     * columns become nullable so a Transportation Invoice/InvoiceItem/
     * AccountsReceivable can exist with no Delivery/SalesOrder/Item to
     * reference (item_code/uom are plain snapshot strings, not FKs, but a
     * manual line has no real Item to snapshot either).
     * Goods keeps populating all of them exactly as before — this only widens
     * what's allowed, no existing data changes. Nullable-only, no index/FK
     * touch — same safe shape already used by supplier_id on payment_entries
     * (2026_08_06_000001_add_general_expense_support_to_payment_entries_table.php),
     * a different and lower-risk mechanism than dropping an FK's last
     * supporting index (the earlier invoices.delivery_id incident): MySQL/
     * InnoDB skips FK checks whenever the referencing value is NULL.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('delivery_id')->nullable()->change();
            $table->uuid('sales_order_id')->nullable()->change();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->uuid('delivery_item_id')->nullable()->change();
            $table->uuid('item_id')->nullable()->change();
            $table->string('item_code')->nullable()->change();
            $table->string('uom')->nullable()->change();
        });

        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->uuid('sales_order_id')->nullable()->change();
            $table->uuid('delivery_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->uuid('sales_order_id')->nullable(false)->change();
            $table->uuid('delivery_id')->nullable(false)->change();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->uuid('delivery_item_id')->nullable(false)->change();
            $table->uuid('item_id')->nullable(false)->change();
            $table->string('item_code')->nullable(false)->change();
            $table->string('uom')->nullable(false)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('delivery_id')->nullable(false)->change();
            $table->uuid('sales_order_id')->nullable(false)->change();
        });
    }
};
