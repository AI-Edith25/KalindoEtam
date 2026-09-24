<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Direct/Non-Stock Purchase Invoice line has no Goods Receipt line and no stock Item — it
     * posts straight to a Chart-of-Accounts expense account, with its own optional tax. The
     * existing `item_name` column is reused as the line's free-text Description for these lines
     * (no new column) — required either way (an Item's name for a GR line, typed text for a
     * Direct line). Nullability relax follows the same pattern as
     * 2026_09_24_000001/000002 (goods_receipt_item_id/item_id/item_code/uom have nothing to
     * snapshot on a Direct line).
     */
    public function up(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->uuid('goods_receipt_item_id')->nullable()->change();
            $table->uuid('item_id')->nullable()->change();
            $table->string('item_code')->nullable()->change();
            $table->string('uom')->nullable()->change();
            $table->foreignUuid('chart_of_account_id')->nullable()->after('item_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignUuid('tax_id')->nullable()->after('chart_of_account_id')
                ->constrained('taxes')->restrictOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_id');
            $table->dropConstrainedForeignId('chart_of_account_id');
            $table->dropColumn('tax_amount');
            $table->string('uom')->nullable(false)->change();
            $table->string('item_code')->nullable(false)->change();
            $table->uuid('item_id')->nullable(false)->change();
            $table->uuid('goods_receipt_item_id')->nullable(false)->change();
        });
    }
};
