<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods Receipt normally carries no tax at all (tax is recorded once a Purchase Invoice is
 * raised) — this column exists only to support the Purchase > Goods Receipts listing export,
 * which needs a Tax/Incl.Tax figure per the legacy reference template. Auto-copied from the
 * linked purchase_order_items row when a GR is created/edited against a PO
 * (GoodsReceiptService), or optionally set manually for a Direct Receipt line with no PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('amount')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
