<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes which cash/bank account is Petty Cash vs. Cash Book for
     * the Journal List report — user-classified via the Chart of Accounts
     * UI, never inferred from a code/name, since is_cash_bank alone doesn't
     * say which kind. Left unset for every existing account; no backfill.
     */
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('cash_bank_category')->nullable()->after('is_cash_bank');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('cash_bank_category');
        });
    }
};
