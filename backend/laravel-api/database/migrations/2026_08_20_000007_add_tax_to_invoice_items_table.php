<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods invoices copy tax_id/tax_amount verbatim from the source
 * DeliveryItem (InvoiceService::createGoods()) — already resolved
 * upstream, same frozen-snapshot treatment as item_code/item_name/uom.
 * Transportation invoices (no item_id) leave these null — unaffected,
 * still header-tax-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('rate')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
