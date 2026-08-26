<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item override for GoodsReceiptService::assertWithinOutstanding() —
     * bulk items (cement) legitimately receive more than the PO qty
     * (truck-scale weight), packaged items never should.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('allow_over_receipt')->default(false)->after('current_stock');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('allow_over_receipt');
        });
    }
};
