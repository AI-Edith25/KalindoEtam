<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AccountsPayableService::createFromInvoice() copies purchase_order_id/goods_receipt_id
     * straight from the invoice — a Direct/Non-Stock Purchase Invoice has neither, so both must
     * accept null here too. Same pattern as 2026_09_24_000001 on purchase_invoices.
     */
    public function up(): void
    {
        Schema::table('accounts_payables', function (Blueprint $table) {
            $table->uuid('purchase_order_id')->nullable()->change();
            $table->uuid('goods_receipt_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_payables', function (Blueprint $table) {
            $table->uuid('goods_receipt_id')->nullable(false)->change();
            $table->uuid('purchase_order_id')->nullable(false)->change();
        });
    }
};
