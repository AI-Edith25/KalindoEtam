<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable at DB level even though StoreSalesOrderRequest requires it — same shape as
 * branch_id/sales_person_id (2026_08_06_000003) — so historical rows just keep warehouse_id
 * null with no backfill needed. Lets pricing resolve against the SO's own warehouse (see
 * ItemPriceResolver) instead of only the customer's Price Zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignUuid('warehouse_id')->nullable()->after('branch_id')->constrained('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
