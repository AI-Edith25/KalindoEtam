<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reverses one row of 2026_09_27_000002_soft_delete_unused_cash_bank_accounts.php: "1104 BANK
 * MANDIRI 5840" was soft-deleted as confirmed-unused, but it turns out to be an active account
 * needed for the new per-account Bank Reconciliation matching -- confirmed with the user. Only
 * this one account is restored; the other 5 soft-deleted in that migration stay deleted.
 */
return new class extends Migration
{
    private const ACCOUNT_ID = '01a074f8-d259-72ee-8969-961e545ab24d'; // 1104 BANK MANDIRI 5840

    public function up(): void
    {
        DB::table('chart_of_accounts')->where('id', self::ACCOUNT_ID)->update(['deleted_at' => null]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('id', self::ACCOUNT_ID)->update(['deleted_at' => now()]);
    }
};
