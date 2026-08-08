<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
