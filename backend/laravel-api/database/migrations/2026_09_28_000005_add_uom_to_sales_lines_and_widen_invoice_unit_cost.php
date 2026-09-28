<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales side of the multi-UOM change (see add_uom_to_purchase_lines_and_widen_fifo_unit_cost):
     * a Sales Order line can be in one of its Item's UOMs; qty/rate stay in that UOM and only the
     * stock boundary converts (base qty = qty × uom_factor). uom_factor is snapshotted down the
     * chain SO → Delivery → Invoice → Credit Note, default 1 so every existing row keeps meaning
     * "already in the item's base UOM". SO lines also keep uom_id (null = base).
     *
     * invoice_items.unit_cost is the per-base-unit FIFO cost snapshot — widened 2 → 6 decimals to
     * match fifo_layers.unit_cost so cost_amount doesn't drift for non-terminating costs.
     */
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->foreignUuid('uom_id')->nullable()->after('item_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('uom_factor', 18, 6)->default(1)->after('uom_id');
        });

        foreach (['delivery_items', 'invoice_items', 'credit_note_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->decimal('uom_factor', 18, 6)->default(1);
            });
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 2)->nullable()->change();
        });

        foreach (['credit_note_items', 'invoice_items', 'delivery_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('uom_factor');
            });
        }

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uom_id');
            $table->dropColumn('uom_factor');
        });
    }
};
