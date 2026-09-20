<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per imported legacy "Customer Unpaid Bills With Overdue Advice" export -- append-only
 * (a re-import never overwrites a prior snapshot, so period-over-period history stays available
 * for reference). Deliberately has no FK to customers/invoices/AR: this archive is a standalone
 * notebook of a past export, never reconciled against the live Sales/AR module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_outstanding_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_filename');
            $table->string('company_name')->nullable();
            $table->date('snapshot_as_of_date');
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('total_customers');
            // File's own printed Grand Total -- validated against the sum of imported lines at
            // import time (see CustomerOutstandingArchiveImportService), stored here so the page
            // can show it without re-summing on every request.
            $table->decimal('grand_total_unpaid', 18, 2);
            $table->decimal('grand_total_overdue', 18, 2);
            $table->foreignUuid('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('snapshot_as_of_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_outstanding_snapshots');
    }
};
