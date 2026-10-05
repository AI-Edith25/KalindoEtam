<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * invoice_items.qty was missed by the 2026_09_06 widening (delivery_items.qty/
     * credit_note_items.qty_credited went decimal then) — a Goods invoice's items are just copied
     * from its Delivery (InvoiceService::createGoods()), so a weigh-category line's decimal qty
     * (e.g. loose/jumbo cement by truck-scale weight) silently truncated going from Delivery into
     * Invoice. Direct Goods invoicing (no Delivery) hit the same ceiling via StoreInvoiceRequest's
     * own 'integer' rule. Storage is decimal end to end; whether a line must actually be a whole
     * number is enforced in the app layer by App\Services\QtyCategoryValidator based on
     * Item.qty_category, same as every other already-fixed module.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->string('qty_category')->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
            $table->integer('qty')->change();
        });
    }
};
