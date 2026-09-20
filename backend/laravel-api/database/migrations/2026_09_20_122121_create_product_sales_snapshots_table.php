<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Product Sales' own batch table -- same multi-period-stacking posture as sales_listing_snapshots, see that migration's docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('source_filename');
            $table->string('company_name')->nullable();
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('total_items');
            $table->decimal('grand_total_qty', 18, 2);
            $table->decimal('grand_total_amount_excl_tax', 18, 2);
            $table->foreignUuid('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_start', 'period_end'], 'pss_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_snapshots');
    }
};
