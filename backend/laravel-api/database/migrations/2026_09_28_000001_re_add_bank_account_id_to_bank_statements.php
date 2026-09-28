<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses part of 2026_09_27_000003_drop_bank_account_id_from_bank_reconciliation.php: the new
 * Detail-tab "Tabel Perbandingan" needs to split/match reconciliation per account (this company
 * does use more than one -- BCA and Mandiri), so bank_account_id comes back on bank_statements.
 * Confirmed with the user this time to be chosen explicitly on upload rather than parsed from the
 * file, since the file format alone (BCA vs Mandiri) can't disambiguate two accounts at the same
 * bank. Nullable at the DB level so existing rows (uploaded before this column existed) don't
 * need a value; new uploads always set one via StoreBankStatementRequest's validation.
 *
 * bank_reconciliation_summaries is deliberately NOT touched -- the Ringkasan tab stays one row
 * per date, aggregated across every account, per the user's own call when this was designed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->foreignUuid('bank_account_id')->nullable()->after('format_template')->constrained('chart_of_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
