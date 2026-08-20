<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery posts nothing financially itself, but DeliveryItem is the
 * required relay point copying tax_id/tax_amount from SalesOrderItem
 * through to InvoiceItem (DeliveryService::addLine() / InvoiceService::createGoods()) —
 * without this column the chain would lose the value at this hop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->foreignUuid('tax_id')->nullable()->after('rate')->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
