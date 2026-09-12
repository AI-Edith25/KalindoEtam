<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * General-expense-purpose lines for a payment_type=mixed Payment Entry — deliberately a
     * separate table from payment_entry_allocations (which stays AP-only, untouched) rather
     * than one unified line table, so every existing AP outstanding-amount query keeps working
     * unmodified. See PaymentEntry::journalLines()'s MIXED branch for how a line here
     * is posted: straight to its own expense_account, no per-line journal (unlike an AP
     * allocation) — it only ever appears inside the one aggregate journal a mixed voucher posts
     * on submit.
     */
    public function up(): void
    {
        Schema::create('payment_entry_expense_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_entry_id')->constrained('payment_entries')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignUuid('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->text('description');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_entry_expense_lines');
    }
};
