<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignUuid('invoice_item_id')->constrained('invoice_items')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            // Snapshots — must not rely solely on the live Item relation for historical accuracy.
            $table->string('item_code');
            $table->string('item_name');
            $table->string('uom');
            $table->integer('qty_credited')->default(0);
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);
            // Intent only this sprint — no StockLedger movement is posted for it yet.
            // See CreditNote::inventoryImpactLabel(): surfaced in the UI as
            // "Pending Inventory Return Module" rather than acted on.
            $table->boolean('restock')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
    }
};
