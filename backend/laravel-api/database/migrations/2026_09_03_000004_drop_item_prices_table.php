<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price Zone removal: in ~1 day of production use only the "QA Test Zone" test row was ever
 * created — no real zone was ever set up, and Area/Warehouse already represents the same
 * region concept Price Zone was meant to capture. Per-warehouse pricing (item_warehouse_prices)
 * replaces this axis entirely. Must drop before price_zones (FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('item_prices');
    }

    public function down(): void
    {
        Schema::create('item_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('price_zone_id')->constrained('price_zones')->restrictOnDelete();
            $table->decimal('rate', 15, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'price_zone_id']);
        });
    }
};
