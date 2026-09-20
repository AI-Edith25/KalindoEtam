<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AP mirror of customer_outstanding_snapshot_lines -- see that migration's own docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_outstanding_snapshot_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('snapshot_id')->constrained('supplier_outstanding_snapshots')->cascadeOnDelete();
            $table->string('supplier_code');
            $table->string('supplier_name');
            $table->date('txn_date');
            $table->string('ref_no');
            $table->decimal('invoice_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2);
            $table->decimal('unpaid_amount', 15, 2);
            $table->unsignedSmallInteger('terms_days')->nullable();
            $table->date('due_date');
            $table->decimal('overdue_amount', 15, 2);
            $table->integer('overdue_days');
            $table->timestamps();

            $table->index(['snapshot_id', 'supplier_code'], 'sosl_snapshot_supplier_idx');
            $table->index(['snapshot_id', 'due_date'], 'sosl_snapshot_due_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_outstanding_snapshot_lines');
    }
};
