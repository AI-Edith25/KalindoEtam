<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_goods_receipts', function (Blueprint $table) {
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            // Unique — this is what actually enforces "a Goods Receipt can never be invoiced twice".
            $table->foreignUuid('goods_receipt_id')->unique()->constrained('goods_receipts')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['purchase_invoice_id', 'goods_receipt_id']);
        });

        Schema::create('purchase_invoice_purchase_orders', function (Blueprint $table) {
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['purchase_invoice_id', 'purchase_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_purchase_orders');
        Schema::dropIfExists('purchase_invoice_goods_receipts');
    }
};
