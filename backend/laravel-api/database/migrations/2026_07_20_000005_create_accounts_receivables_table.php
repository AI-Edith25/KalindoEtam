<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_receivables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignUuid('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->string('reference_number')->comment('Snapshot of the originating Delivery document_number, for reporting');
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0)->comment('Not updated yet — no payment module this sprint');
            $table->date('due_date');
            $table->string('status')->default('unpaid');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivables');
    }
};
