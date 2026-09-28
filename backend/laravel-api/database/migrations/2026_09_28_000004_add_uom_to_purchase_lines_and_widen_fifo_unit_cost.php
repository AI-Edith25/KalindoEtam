<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Purchase Order line can be in one of its Item's UOMs (Item.uoms). qty/rate stay in that
     * UOM; only the stock boundary converts (base qty = qty × uom_factor, FIFO unit cost =
     * rate ÷ uom_factor). uom_factor is a snapshot on every line down the chain (PO → Goods
     * Receipt → Purchase Invoice → Purchase Return) defaulting to 1, so every existing row
     * keeps meaning "already in the item's base UOM". PO lines also keep uom_id (null = base).
     *
     * FIFO unit_cost is widened 2 → 6 decimals: rate ÷ factor (Rp100.000 ÷ 3) would otherwise
     * be rounded per unit and drift the layer's total cost.
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignUuid('uom_id')->nullable()->after('item_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('uom_factor', 18, 6)->default(1)->after('uom_id');
        });

        foreach (['goods_receipt_items', 'purchase_invoice_items', 'purchase_return_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->decimal('uom_factor', 18, 6)->default(1);
            });
        }

        Schema::table('fifo_layers', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 6)->change();
        });

        Schema::table('fifo_layer_consumptions', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 6)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fifo_layer_consumptions', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 2)->change();
        });

        Schema::table('fifo_layers', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 2)->change();
        });

        foreach (['purchase_return_items', 'purchase_invoice_items', 'goods_receipt_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('uom_factor');
            });
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uom_id');
            $table->dropColumn('uom_factor');
        });
    }
};
