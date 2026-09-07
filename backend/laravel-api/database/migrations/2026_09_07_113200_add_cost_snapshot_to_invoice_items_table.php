<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // COGS snapshot at invoice-creation time — never recomputed from the item's current
            // cost later (see InvoiceService::createGoods()). Null for Transportation invoices
            // (no item/COGS concept) and for any historical row the backfill migration below
            // couldn't resolve.
            $table->decimal('unit_cost', 18, 2)->nullable()->after('amount');
            $table->decimal('cost_amount', 15, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'cost_amount']);
        });
    }
};
