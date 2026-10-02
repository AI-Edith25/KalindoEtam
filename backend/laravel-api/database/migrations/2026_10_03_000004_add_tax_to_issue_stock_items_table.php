<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue Stock never posts to GL (only StockLedger/FifoLayer) — this column is purely
 * informational/reporting, same role Tax plays on Opening/Receipt Stock. tax_id can be
 * picked at Draft time, but tax_amount stays null until submit() — amount (and therefore
 * the taxable base) isn't known until FifoLayerService::consume() resolves it, same
 * deferral unit_cost/amount already go through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_stock_items', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('amount')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 18, 2)->nullable()->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('issue_stock_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
