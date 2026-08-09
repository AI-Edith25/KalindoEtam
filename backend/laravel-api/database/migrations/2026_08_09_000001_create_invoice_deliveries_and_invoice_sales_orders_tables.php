<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoice-from-multiple-Deliveries: invoices.delivery_id/sales_order_id
     * stay exactly as they are (NOT NULL, FK) and become "anchor" columns —
     * only delivery_id's UNIQUE index is dropped, since these two new pivot
     * tables are now the authoritative one-to-many source of truth.
     * invoice_deliveries.delivery_id is itself UNIQUE, which is what
     * replaces the old invoices.delivery_id unique constraint as the real
     * "a Delivery can never be invoiced twice" enforcement. No surrogate
     * `id` column, composite/plain-FK pivot shape instead — same shape
     * Eloquent's belongsToMany()->sync() expects by default, and the same
     * shape already used by Spatie's own model_has_permissions/model_has_roles
     * pivots in this codebase.
     */
    public function up(): void
    {
        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('delivery_id')->unique()->constrained('deliveries')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['invoice_id', 'delivery_id']);
        });

        Schema::create('invoice_sales_orders', function (Blueprint $table) {
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['invoice_id', 'sales_order_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['delivery_id']);
        });

        // Backfill: every existing invoice becomes consistent with the new
        // relations immediately, from its own current anchor columns.
        $now = now();
        foreach (DB::table('invoices')->select('id', 'delivery_id', 'sales_order_id')->cursor() as $invoice) {
            DB::table('invoice_deliveries')->insert([
                'invoice_id' => $invoice->id,
                'delivery_id' => $invoice->delivery_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('invoice_sales_orders')->insert([
                'invoice_id' => $invoice->id,
                'sales_order_id' => $invoice->sales_order_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('delivery_id');
        });

        Schema::dropIfExists('invoice_sales_orders');
        Schema::dropIfExists('invoice_deliveries');
    }
};
