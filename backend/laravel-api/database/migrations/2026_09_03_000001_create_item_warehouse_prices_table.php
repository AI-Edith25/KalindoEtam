<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-warehouse sale price override for an item — same shape and reasoning as item_prices
     * (Price Zone): no soft deletes, removing an override just falls back to items.standard_rate
     * (or, when items.sync_to_main_wh is set, to the Main warehouse's own resolved price),
     * history of the rate lives in audit_logs via AuditLogService.
     */
    public function up(): void
    {
        Schema::create('item_warehouse_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('rate', 15, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_warehouse_prices');
    }
};
