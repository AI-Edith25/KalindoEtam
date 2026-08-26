<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->nullable()->unique();
            $table->string('status')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason');
            $table->decimal('subtotal', 15, 2)->default(0)->comment('Cache only, derived from purchase_return_items');
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0)->comment('Cache only, derived: subtotal + tax_amount');
            $table->text('remarks')->nullable();
            $table->boolean('is_reversed')->default(false);
            $table->dateTime('reversed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
