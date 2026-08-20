<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line tax — defaults from the line's Item.sales_tax_id when first
 * added (SalesOrderService::resolveLineTax()), editable per line.
 * tax_amount is a cache column, same discipline as the header's own
 * tax_amount (docs/TAX_ENGINE_DESIGN.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('rate')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
