<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fifo_layers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // Mirrors stock_ledgers.voucher_type/voucher_id — a plain string, not a DB enum, so
            // adding a new StockVoucherType case (e.g. opening_stock, credit_note) never needs a migration.
            $table->string('source_type');
            $table->uuid('source_id');
            $table->string('source_document_number')->nullable();
            $table->date('received_date');
            $table->decimal('qty_in', 18, 4);
            $table->decimal('qty_remaining', 18, 4);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('total_cost', 18, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Consumption order: oldest received_date first, created_at as tiebreaker —
            // same shape as StockLedgerRepository::latestBalance()'s ordering.
            $table->index(['item_id', 'warehouse_id', 'received_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fifo_layers');
    }
};
