<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank Reconciliation drops the per-bank-account dimension entirely -- confirmed with the user:
 * the mutasi/journal-list files this compares have no reliable structured "which account" field
 * to hang a per-account split on (see the investigation that also led to
 * 2026_09_27_000002_soft_delete_unused_cash_bank_accounts.php), and only one physical source has
 * ever actually been reconciled through this feature anyway. One combined bucket per day, not one
 * per (account, day), matching BankReconciliationService's new shape.
 *
 * Existing production data (bank_statements/bank_statement_lines/bank_reconciliation_summaries)
 * is untouched otherwise -- this only drops the now-unused bank_account_id column and the
 * per-account unique constraint on the summary table, replacing it with a unique-per-date
 * constraint (safe: this company only ever had one account's rows in that table to begin with).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliation_summaries', function (Blueprint $table) {
            $table->dropUnique(['bank_account_id', 'date']);
            $table->dropConstrainedForeignId('bank_account_id');
            $table->unique('date');
        });

        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->foreignUuid('bank_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
        });

        Schema::table('bank_reconciliation_summaries', function (Blueprint $table) {
            $table->dropUnique(['date']);
            $table->foreignUuid('bank_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->unique(['bank_account_id', 'date']);
        });
    }
};
