<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_stock_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('opening_stock_id')->constrained('opening_stocks')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            // Snapshots — must not rely solely on the live Item relation for historical accuracy,
            // same convention as every other line-item table in this app.
            $table->string('item_code');
            $table->string('item_name');
            $table->string('uom');
            $table->string('qty_category')->nullable();
            // Deliberately no unique constraint on (opening_stock_id, item_id) — the same item can
            // have more than one line at a different cost, one FIFO layer per line (the ticket's
            // explicit point: "itu justru inti FIFO").
            $table->decimal('qty', 18, 4);
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
        Schema::dropIfExists('opening_stock_items');
    }
};
