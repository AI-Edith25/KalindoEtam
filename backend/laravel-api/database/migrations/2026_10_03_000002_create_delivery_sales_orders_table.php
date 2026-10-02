<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery-from-multiple-Sales-Orders, same shape as 2026_08_09_000001's
     * invoice_sales_orders pivot. deliveries.sales_order_id stays exactly as
     * it is (nullable, FK, never unique — a Sales Order could already span
     * several Deliveries before this migration) and becomes the "anchor"
     * column; this pivot is the new authoritative one-to-many source of
     * truth for "which Sales Orders did this Delivery come from". No
     * unique-index migration dance is needed here (unlike invoice_deliveries),
     * since deliveries.sales_order_id was never unique to begin with.
     */
    public function up(): void
    {
        if (! Schema::hasTable('delivery_sales_orders')) {
            Schema::create('delivery_sales_orders', function (Blueprint $table) {
                $table->foreignUuid('delivery_id')->constrained('deliveries')->cascadeOnDelete();
                $table->foreignUuid('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
                $table->timestamps();
                $table->primary(['delivery_id', 'sales_order_id']);
            });
        }

        // Backfill: every existing Delivery becomes consistent with the new pivot from its
        // own current anchor column. insertOrIgnore makes this safe to re-run.
        $now = now();
        foreach (DB::table('deliveries')->select('id', 'sales_order_id')->whereNotNull('sales_order_id')->cursor() as $delivery) {
            DB::table('delivery_sales_orders')->insertOrIgnore([
                'delivery_id' => $delivery->id,
                'sales_order_id' => $delivery->sales_order_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_sales_orders');
    }
};
