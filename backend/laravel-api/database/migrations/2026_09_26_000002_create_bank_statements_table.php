<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Header/upload record for one bank statement file. `bank_account_id` points at a
 * chart_of_accounts row with is_cash_bank=true -- the same "bank account" concept
 * Payment Voucher/Official Receipt already use as cash_account_id, no separate
 * BankAccount model. created_by/created_at (via HasAuditTrail) double as the
 * ticket's uploaded_by/uploaded_at, same as import_batches does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('format_template');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('original_filename');
            $table->string('disk')->default('local');
            $table->string('file_path');
            $table->string('status')->default('uploaded');
            $table->text('error_message')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
