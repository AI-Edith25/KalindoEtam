<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional truck-scale weight recorded alongside a Goods Receipt line —
     * pure record-keeping, never read by qty/price/stock/AP logic anywhere.
     * See GoodsReceiptService for the sibling-field pass-through and
     * StoreGoodsReceiptRequest for validation (all nullable).
     */
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('actual_weight', 18, 2)->nullable()->after('qty');
            $table->string('weight_unit', 8)->nullable()->after('actual_weight');
            $table->string('weighbridge_ref', 64)->nullable()->after('weight_unit');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn(['actual_weight', 'weight_unit', 'weighbridge_ref']);
        });
    }
};
