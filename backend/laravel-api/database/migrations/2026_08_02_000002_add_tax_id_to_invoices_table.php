<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document-level tax assignment (docs/TAX_ENGINE_DESIGN.md §5) — invoices
 * gains one nullable FK. tax_amount (existing column) stays the cached,
 * derived result of TaxService::calculate(), never a second source of
 * truth. No change to invoice_items — no per-line taxation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('discount_amount')->constrained('taxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
