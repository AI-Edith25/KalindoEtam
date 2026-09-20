<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_listing_snapshot_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('snapshot_id')->constrained('sales_listing_snapshots')->cascadeOnDelete();
            $table->date('txn_date');
            $table->string('document_number');
            $table->string('reference_so')->nullable();
            $table->string('reference_do')->nullable();
            $table->string('customer_code');
            $table->string('customer_name');
            // Raw Skybiz code (e.g. "CusInv") -- never coerced at parse time, see
            // SalesListingArchiveService for the display-time label mapping.
            $table->string('type_code');
            $table->decimal('amount_excl_tax', 18, 2);
            $table->decimal('disc_adjustment', 18, 2);
            $table->decimal('tax', 18, 2);
            $table->decimal('amount_incl_tax', 18, 2);
            $table->timestamps();

            $table->index('snapshot_id', 'sll_snapshot_idx');
            $table->index('document_number', 'sll_document_number_idx');
            $table->index('customer_code', 'sll_customer_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_listing_snapshot_lines');
    }
};
