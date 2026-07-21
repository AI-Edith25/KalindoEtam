<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->nullable()->unique();
            $table->string('status')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('payment_method');
            $table->string('reference_number')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0)->comment('Cache only, derived from receipt_entry_items');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_entries');
    }
};
