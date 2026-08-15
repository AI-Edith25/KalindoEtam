<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same shape as Purchase Order's own tax_id/tax_amount pair
 * (2026_08_02_000003_add_tax_to_purchase_orders_table.php) — total_amount
 * (existing column) keeps its existing meaning as the pre-tax subtotal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('total_amount')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id')->comment('Cache only, derived from TaxService::calculate()');
            $table->decimal('grand_total', 15, 2)->default(0)->after('tax_amount')->comment('Cache only, total_amount + tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['tax_amount', 'grand_total']);
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
