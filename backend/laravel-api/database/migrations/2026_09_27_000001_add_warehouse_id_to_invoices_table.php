<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Direct Goods Invoice (Jumbo & Curah, no Sales Order/Delivery) needs its own Location, since there's no Delivery to inherit warehouse_id from. Null for every other invoice_type/flow. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('warehouse_id')->nullable()->after('sales_order_id')->constrained('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
