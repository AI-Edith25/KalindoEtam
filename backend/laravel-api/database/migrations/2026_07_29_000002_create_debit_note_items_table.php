<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_note_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('debit_note_id')->constrained('debit_notes')->cascadeOnDelete();
            // Nullable, unlike credit_note_items.invoice_item_id — Additional Service
            // Charge / Freight Adjustment lines have no InvoiceItem to point at.
            $table->foreignUuid('invoice_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('items')->restrictOnDelete();
            // Snapshots — set only when invoice_item_id is set.
            $table->string('item_code')->nullable();
            $table->string('item_name')->nullable();
            $table->string('uom')->nullable();
            $table->string('description');
            $table->integer('qty_adjusted')->default(0);
            $table->decimal('rate', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_note_items');
    }
};
