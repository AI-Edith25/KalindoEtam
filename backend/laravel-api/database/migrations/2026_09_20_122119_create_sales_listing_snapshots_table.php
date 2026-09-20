<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per imported "01 Sales Listing" period export -- unlike the AR/AP archives
 * (single "latest wins" snapshot), Sales Report keeps every imported period side by side;
 * re-importing the same (period_start, period_end) replaces just that one row (and its
 * cascaded lines), a different period is added alongside it. No FK to invoices/credit_notes/
 * customers -- standalone notebook, same posture as customer_outstanding_snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_listing_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('source_filename');
            $table->string('company_name')->nullable();
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('total_documents');
            $table->decimal('grand_total_amount_excl_tax', 18, 2);
            $table->decimal('grand_total_amount_incl_tax', 18, 2);
            $table->foreignUuid('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_start', 'period_end'], 'sls_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_listing_snapshots');
    }
};
