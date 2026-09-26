<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized mutation lines, format-independent (see BankStatementParser). Match
 * target is a PaymentEntry or ReceiptEntry document, not a JournalEntry row -- same
 * nullable-morph shape as journal_entries.reference_type/reference_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->decimal('debit_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2)->default(0);
            $table->decimal('running_balance', 15, 2)->nullable();
            $table->string('matched_document_type')->nullable();
            $table->uuid('matched_document_id')->nullable();
            $table->string('match_status')->default('unmatched');
            $table->timestamps();

            $table->index(['bank_statement_id', 'transaction_date']);
            // Explicit short name -- the auto-generated one (with table+both column
            // names) exceeds MySQL's 64-char identifier limit.
            $table->index(['matched_document_type', 'matched_document_id'], 'bank_statement_lines_matched_doc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
