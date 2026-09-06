<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * delivery_items.qty and credit_note_items.qty_credited were missed by the
     * 2026_08_27_000008 widening (Goods Receipt/Purchase Order/Stock Adjustment/Stock
     * Transfer/Purchase Return all went decimal(18,4) with a qty_category snapshot then;
     * Delivery and Credit Note stayed integer). FIFO layer consumption needs a Delivery's
     * (and a restocking Credit Note's) own line qty to carry real fractional quantities —
     * same rationale, same shape as that migration. Storage is decimal end to end; whether
     * a line must actually be a whole number is still enforced by
     * App\Services\QtyCategoryValidator based on Item.qty_category, not the column type.
     */
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('qty');
        });

        Schema::table('credit_note_items', function (Blueprint $table) {
            $table->decimal('qty_credited', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('qty_credited');
        });
    }

    public function down(): void
    {
        Schema::table('credit_note_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty_credited')->change();
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty')->change();
        });
    }
};
