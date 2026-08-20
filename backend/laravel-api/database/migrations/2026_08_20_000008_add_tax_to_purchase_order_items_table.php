<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line tax — defaults from the line's Item.purchase_tax_id when first
 * added (PurchaseOrderService::resolveLineTax()), editable per line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('rate')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
