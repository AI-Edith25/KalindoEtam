<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening Stock never posts to GL (only StockLedger/FifoLayer) — this column is purely
 * informational/reporting, same role Tax plays on a Direct Goods Receipt line. No source
 * document to inherit from, so it's manual-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_stock_items', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('amount')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 18, 2)->default(0)->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('opening_stock_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
