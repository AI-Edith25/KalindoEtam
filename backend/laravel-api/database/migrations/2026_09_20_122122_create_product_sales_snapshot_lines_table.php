<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw per-detail rows, not pre-aggregated per item -- item/item-group totals and date
 * filtering are recomputed at query time (ProductSalesArchiveService), same "recompute,
 * don't trust the file's own subtotal" rule already established for Perincian Piutang.
 * item_code/item_description/item_group are inherited from the item (subtotal) row each
 * detail row sat under in the source file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales_snapshot_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('snapshot_id')->constrained('product_sales_snapshots')->cascadeOnDelete();
            $table->date('txn_date');
            $table->string('document_number');
            $table->string('item_code');
            $table->string('item_description');
            $table->string('item_group')->nullable();
            $table->string('customer_code')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('qty', 18, 2);
            $table->decimal('base_qty', 18, 2);
            $table->decimal('amount_excl_tax', 18, 2);
            $table->decimal('tax', 18, 2)->nullable();
            $table->decimal('amount_incl_tax', 18, 2)->nullable();
            $table->timestamps();

            $table->index('snapshot_id', 'psl_snapshot_idx');
            $table->index('item_code', 'psl_item_code_idx');
            $table->index('customer_code', 'psl_customer_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_snapshot_lines');
    }
};
