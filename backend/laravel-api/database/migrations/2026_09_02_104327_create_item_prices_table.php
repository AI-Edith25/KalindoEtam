<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-zone sale price override for an item. No soft deletes: removing an override has no
     * "undo" business value (the item just falls back to items.standard_rate) — history of the
     * rate itself lives in audit_logs via AuditLogService, not a parallel deleted_at trail here.
     */
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('item_prices');
    }
};
