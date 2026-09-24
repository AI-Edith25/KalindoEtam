<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct/Non-Stock Purchase Invoices have no source Goods Receipt or Purchase Order — the
     * FKs stay, only the NOT NULL constraint drops. Same safe nullable-FK pattern as
     * 2026_08_27_000003_make_goods_receipt_purchase_order_nullable. `source` defaults every
     * existing row to 'goods_receipt' at the DB level (no separate backfill UPDATE needed).
     */
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->uuid('goods_receipt_id')->nullable()->change();
            $table->uuid('purchase_order_id')->nullable()->change();
            $table->string('source')->default('goods_receipt')->after('supplier_id');
            $table->string('attention')->nullable()->after('reference_number')->comment('Direct invoice only — e.g. vehicle plate number');
            $table->string('department')->nullable()->after('attention')->comment('Direct invoice only — free text, no Department master exists in this schema');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['source', 'attention', 'department']);
            $table->uuid('purchase_order_id')->nullable(false)->change();
            $table->uuid('goods_receipt_id')->nullable(false)->change();
        });
    }
};
