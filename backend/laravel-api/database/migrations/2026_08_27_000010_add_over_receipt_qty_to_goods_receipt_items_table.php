<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            // Computed server-side only (GoodsReceiptService), never trusted from the client —
            // the portion of this line's qty that pushed the PO item's received total past its
            // outstanding qty. Report traceability for Weight-category over-receipt.
            $table->decimal('over_receipt_qty', 18, 4)->default(0)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn('over_receipt_qty');
        });
    }
};
