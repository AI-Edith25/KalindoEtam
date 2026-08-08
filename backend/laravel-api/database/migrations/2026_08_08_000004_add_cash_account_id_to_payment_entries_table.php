<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same shape as receipt_entries' own cash_account_id migration — see
     * that file's docblock for the full rationale. Replaces
     * PaymentEntry::cashAccountCode()'s hardcoded '1100' with a per-record
     * chosen account, mirroring the existing expense_account_id column
     * exactly.
     */
    public function up(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->change();
        });

        Schema::table('payment_entries', function (Blueprint $table) {
            $table->foreignUuid('cash_account_id')->nullable()->after('payment_date')->constrained('chart_of_accounts')->restrictOnDelete();
        });

        $cashAccountId = DB::table('chart_of_accounts')->where('code', '1100')->value('id');

        if ($cashAccountId !== null) {
            DB::table('payment_entries')->update(['cash_account_id' => $cashAccountId]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
        });

        Schema::table('payment_entries', function (Blueprint $table) {
            $table->string('payment_method')->nullable(false)->change();
        });
    }
};
