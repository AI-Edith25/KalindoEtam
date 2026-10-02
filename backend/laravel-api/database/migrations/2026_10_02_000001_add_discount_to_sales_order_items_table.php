<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line discount — SalesOrderService::replaceItems()/syncApprovedItems() resolve this via
 * DiscountService, same cache-column discipline tax_id/tax_amount already use on this table.
 * discount_type defaults 'amount'/0 so every existing row (created before this column existed)
 * reads as "no discount", identical to its current real behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->string('discount_type')->default('amount')->after('amount');
            $table->decimal('discount_value', 15, 2)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_value');
            $table->decimal('net_amount', 15, 2)->default(0)->after('discount_amount');
        });

        // Schema-default backfill only (net = gross, since no discount existed before this
        // column) — not a recalculation of existing documents, which stay untouched by design.
        DB::table('sales_order_items')->update(['net_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount', 'net_amount']);
        });
    }
};
