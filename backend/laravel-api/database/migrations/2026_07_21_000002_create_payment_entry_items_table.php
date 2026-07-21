<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_entry_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_entry_id')->constrained('payment_entries')->cascadeOnDelete();
            $table->foreignUuid('accounts_payable_id')->constrained('accounts_payables')->restrictOnDelete();
            $table->decimal('paid_amount', 15, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_entry_items');
    }
};
