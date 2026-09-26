<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily balancing rollup per bank account -- read by the Dashboard and, later, by
 * a separate WhatsApp automation via BankReconciliationService::getDailyBalancingSummary().
 * One row per (bank_account_id, date), upserted on every recompute. Absence of a row
 * for a given bank+date is itself meaningful (no statement uploaded yet) -- callers
 * must not treat a missing row as "balanced" or "zero".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->date('date');
            $table->decimal('system_debit_total', 15, 2)->default(0);
            $table->decimal('system_credit_total', 15, 2)->default(0);
            $table->decimal('statement_debit_total', 15, 2)->default(0);
            $table->decimal('statement_credit_total', 15, 2)->default(0);
            $table->decimal('variance_debit', 15, 2)->default(0);
            $table->decimal('variance_credit', 15, 2)->default(0);
            $table->string('status');
            $table->dateTime('generated_at');
            $table->timestamps();

            $table->unique(['bank_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_summaries');
    }
};
