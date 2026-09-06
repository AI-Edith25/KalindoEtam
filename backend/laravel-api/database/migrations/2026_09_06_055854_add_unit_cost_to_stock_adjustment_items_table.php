<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only meaningful when counted_qty > system_qty (a physical count found more than the
     * ledger says) — that's the one direction a Stock Adjustment can create a new FIFO layer,
     * and the form has never had a cost input at all. Nullable: a negative/zero difference
     * line never needs it (it consumes existing layers instead, no new cost to record).
     */
    public function up(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 2)->nullable()->after('difference_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
