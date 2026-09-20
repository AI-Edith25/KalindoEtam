<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP mirror of customer_outstanding_snapshots -- one row per imported legacy "Supplier
 * Outstanding Bills" export, append-only. No FK to suppliers/invoices/AP: this archive is a
 * standalone notebook of a past export, never reconciled against the live Purchase/AP module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_outstanding_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_filename');
            $table->string('company_name')->nullable();
            $table->date('snapshot_as_of_date');
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('total_suppliers');
            $table->decimal('grand_total_unpaid', 18, 2);
            $table->decimal('grand_total_overdue', 18, 2);
            $table->foreignUuid('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('snapshot_as_of_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_outstanding_snapshots');
    }
};
