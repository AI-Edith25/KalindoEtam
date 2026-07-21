<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order is the closest existing Purchase document to extend —
 * no Purchase Invoice/Bill document type exists yet in this codebase (see
 * docs/TAX_ENGINE_DESIGN.md Open Question 1). Same shape as Invoice's own
 * tax_id/tax_amount pair; total_amount (existing column) keeps its
 * existing meaning as the pre-tax subtotal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('total_amount')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id')->comment('Cache only, derived from TaxService::calculate()');
            $table->decimal('grand_total', 15, 2)->default(0)->after('tax_amount')->comment('Cache only, total_amount + tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['tax_amount', 'grand_total']);
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
