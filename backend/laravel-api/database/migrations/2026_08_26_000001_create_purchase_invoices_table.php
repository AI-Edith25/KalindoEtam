<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->nullable()->unique();
            $table->string('status')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            // Anchor (earliest receipt_date among the selected Goods Receipts) — kept for
            // quick access, NOT unique. The pivot table enforces "never invoiced twice".
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('subtotal', 15, 2)->default(0)->comment('Cache only, derived from purchase_invoice_items');
            $table->decimal('tax_amount', 15, 2)->default(0)->comment('Manual entry — Goods Receipt items do not carry a tax snapshot');
            $table->decimal('grand_total', 15, 2)->default(0)->comment('Cache only, derived: subtotal + tax_amount');
            $table->string('reference_number')->nullable()->comment('Supplier\'s own invoice number, for AP matching/reporting');
            $table->text('remarks')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
