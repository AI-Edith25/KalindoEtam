<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * total_discount = sum of every line's discount_amount. tax_base (DPP) = sum of every line's
 * net_amount = total_amount - total_discount. Both are cache columns, same discipline
 * total_amount/tax_amount already use — the per-line sum in SalesOrderService is authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('total_discount', 15, 2)->default(0)->after('total_amount');
            $table->decimal('tax_base', 15, 2)->default(0)->after('total_discount');
        });

        DB::table('sales_orders')->update(['tax_base' => DB::raw('total_amount')]);
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['total_discount', 'tax_base']);
        });
    }
};
