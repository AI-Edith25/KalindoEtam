<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approval-gated, one-time nominal unlock for a Submitted Transportation
     * Invoice — see InvoiceChangeRequestService. Deliberately not built on
     * ApprovalFlow: that model's requestApproval() is a pre-submit "can this
     * document be submitted" gate, a different concept from a post-submit
     * temporary edit unlock.
     */
    public function up(): void
    {
        // Recovery path: an earlier deploy of this migration (before requested_by_id
        // was nullable) failed partway through on MySQL — CREATE TABLE and the
        // invoice_id foreign key succeed, but requested_by_id's ADD CONSTRAINT
        // ... ON DELETE SET NULL is rejected by MySQL because a SET NULL action
        // requires a nullable column (error 1830), which aborts up() before
        // decided_by_id/created_by/updated_by/deleted_by's own foreign keys are
        // ever added. That leaves an orphaned, incompletely-built table that
        // Laravel's migrations table never recorded as applied, so a retry hits
        // "table already exists" (error 1050) on the CREATE TABLE below. Since
        // no successful migration ever ran, the application could not have
        // durably relied on this table — but if something did insert rows before
        // this fix landed, refuse to touch it rather than silently dropping data.
        if (Schema::hasTable('invoice_change_requests')) {
            $rowCount = DB::table('invoice_change_requests')->count();

            if ($rowCount > 0) {
                throw new RuntimeException(
                    "invoice_change_requests already exists with {$rowCount} row(s) — refusing to drop it automatically. ".
                    'Inspect its contents manually (this table was left behind by a prior failed migration attempt, '.
                    'see this migration\'s up() for the full explanation) before deciding how to proceed.'
                );
            }

            Schema::drop('invoice_change_requests');
        }

        Schema::create('invoice_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->foreignUuid('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('request_reason');
            $table->foreignUuid('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_remarks')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_change_requests');
    }
};
