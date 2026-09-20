<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per invoice line under a customer_outstanding_snapshots parent. customer_code/name are
 * snapshotted as plain strings, not a customer_id FK -- this archive must still be readable if a
 * customer is later renamed or removed from the live master, and it's never meant to join back
 * to it. Per-customer/grand-total subtotals from the source file are validation-only (checked at
 * import time) and not persisted as their own rows -- trivially re-derived with SUM/GROUP BY at
 * read time from a table this size (a few thousand rows per snapshot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_outstanding_snapshot_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('snapshot_id')->constrained('customer_outstanding_snapshots')->cascadeOnDelete();
            $table->string('customer_code');
            $table->string('customer_name');
            $table->date('txn_date');
            $table->string('ref_no');
            $table->decimal('invoice_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2);
            $table->decimal('unpaid_amount', 15, 2);
            $table->unsignedSmallInteger('terms_days')->nullable();
            $table->date('due_date');
            $table->decimal('overdue_amount', 15, 2);
            // Signed -- a row can be not-yet-due (0 or negative "days until due" isn't printed by
            // this export, but overdue_days as given is trusted verbatim, never recomputed here).
            $table->integer('overdue_days');
            $table->timestamps();

            // Explicit short names -- the auto-generated ones (table name + both columns +
            // "_index") exceed MySQL's 64-char identifier limit.
            $table->index(['snapshot_id', 'customer_code'], 'cosl_snapshot_customer_idx');
            $table->index(['snapshot_id', 'due_date'], 'cosl_snapshot_due_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_outstanding_snapshot_lines');
    }
};
