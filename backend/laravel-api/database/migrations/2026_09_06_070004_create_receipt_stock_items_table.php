<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_stock_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('receipt_stock_id')->constrained('receipt_stocks')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('item_code');
            $table->string('item_name');
            $table->string('uom');
            $table->string('qty_category')->nullable();
            $table->decimal('qty', 18, 4);
            // User-entered — this is incoming stock with no natural cost source, unlike Issue Stock.
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_stock_items');
    }
};
