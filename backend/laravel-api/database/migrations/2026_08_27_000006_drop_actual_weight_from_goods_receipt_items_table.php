<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverts 2026_08_27_000005 — the "Actual Weight" field was the wrong
     * fix. Qty's integer-vs-decimal behavior is decided per Item
     * (Item.qty_category), not by a separate weight field bolted onto
     * Goods Receipt lines. See the qty_category migration/ticket for the
     * real design. Any actual_weight/weight_unit/weighbridge_ref data
     * entered while this was live is dropped — the feature is cancelled,
     * not superseded.
     */
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn(['actual_weight', 'weight_unit', 'weighbridge_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('actual_weight', 18, 2)->nullable()->after('qty');
            $table->string('weight_unit', 8)->nullable()->after('actual_weight');
            $table->string('weighbridge_ref', 64)->nullable()->after('weight_unit');
        });
    }
};
