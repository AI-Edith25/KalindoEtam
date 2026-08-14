<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('miscellaneous_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('misc_code')->unique();
            $table->string('description');
            $table->decimal('rate', 15, 2)->default(0.00);
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->nullOnDelete();
            $table->string('charge_type')->default('addition');
            $table->decimal('unit_cost', 15, 2)->default(0.00);
            $table->foreignUuid('sales_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignUuid('purchase_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('miscellaneous_items');
    }
};
