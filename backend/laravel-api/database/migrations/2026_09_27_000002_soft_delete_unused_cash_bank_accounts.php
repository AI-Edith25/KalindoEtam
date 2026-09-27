<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: 6 of this company's 10 `is_cash_bank` chart_of_accounts rows were never used
 * anywhere -- confirmed zero rows in journal_entry_lines, payment_entries (as either
 * cash_account_id or expense_account_id), payment_entry_expense_lines, receipt_entries,
 * bank_reconciliation_summaries, bank_statements, and report_account_mappings, verified
 * directly against production immediately before writing this migration. User-confirmed as
 * genuine leftover/test entries, safe to remove (see the "10 duplicate rows in Bank
 * Reconciliation" investigation).
 *
 * Soft-delete, not a hard DELETE:
 * - Every one of those 8 tables' FKs into chart_of_accounts is restrictOnDelete(), so a real
 *   DELETE would need those checked anyway; soft-delete needs none of that ceremony since it's
 *   just an UPDATE, and it IS the backup the user asked for -- the row is never destroyed, only
 *   deleted_at is set, fully reversible by nulling it back out (see down()).
 * - Matches the exact precedent already used once in this same table for a prior chart-of-
 *   accounts cleanup (10 other duplicate rows soft-deleted on 2026-09-19).
 * - The Bank Reconciliation "every is_cash_bank account gets a row per day" bug this was
 *   investigating is separately fixed at the query level (BankReconciliationService::
 *   getDailyBalancingSummary() now only synthesizes rows for accounts that have ever had a
 *   statement uploaded) -- this migration's only job is removing 6 confirmed-unused rows the
 *   user no longer wants cluttering the Chart of Accounts list itself.
 *
 * Idempotent: re-running only touches rows that are still not already deleted.
 */
return new class extends Migration
{
    /** @var string[] */
    private const ACCOUNT_IDS = [
        '01a074f8-d291-732c-8a87-2d46d28f33a6', // 1102 BANK BCA BPP 6189
        '01a074f8-d259-72ee-8969-961e545ab24d', // 1104 BANK MANDIRI 5840
        '01a074f8-d273-700d-b83b-a29e9c46db56', // 1105 BANK OCBC NISP 3383
        '01a0b781-e3f7-71cf-8155-cbbe55a78b5a', // 1107 BANK OCBC NISP 8466
        '01a074f8-d32b-71f9-8243-35c123c5e06e', // 1002 KAS BALIKPAPAN
        '01a0b772-7138-71a3-9269-df56a8adb178', // 1003 KAS KECIL BALIKPAPAN
    ];

    public function up(): void
    {
        // Defense in depth: re-verify zero references at migration-run time too, not just at
        // authoring time -- abort loudly rather than silently soft-deleting an account that
        // gained real activity between now and when this was written.
        $referenceChecks = [
            'journal_entry_lines' => 'chart_of_account_id',
            'payment_entry_expense_lines' => 'expense_account_id',
            'bank_reconciliation_summaries' => 'bank_account_id',
            'bank_statements' => 'bank_account_id',
            'report_account_mappings' => 'chart_of_account_id',
        ];

        foreach (self::ACCOUNT_IDS as $accountId) {
            foreach ($referenceChecks as $table => $column) {
                $count = DB::table($table)->where($column, $accountId)->count();
                if ($count > 0) {
                    throw new \RuntimeException("Refusing to soft-delete chart_of_accounts {$accountId}: {$count} row(s) reference it via {$table}.{$column}.");
                }
            }

            $paymentCount = DB::table('payment_entries')->where('cash_account_id', $accountId)->orWhere('expense_account_id', $accountId)->count();
            if ($paymentCount > 0) {
                throw new \RuntimeException("Refusing to soft-delete chart_of_accounts {$accountId}: {$paymentCount} row(s) reference it via payment_entries.");
            }

            $receiptCount = DB::table('receipt_entries')->where('cash_account_id', $accountId)->count();
            if ($receiptCount > 0) {
                throw new \RuntimeException("Refusing to soft-delete chart_of_accounts {$accountId}: {$receiptCount} row(s) reference it via receipt_entries.cash_account_id.");
            }
        }

        $affected = DB::table('chart_of_accounts')
            ->whereIn('id', self::ACCOUNT_IDS)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        fwrite(STDOUT, "  Soft-deleted {$affected} unused cash/bank chart_of_accounts row(s).\n");
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')
            ->whereIn('id', self::ACCOUNT_IDS)
            ->update(['deleted_at' => null]);
    }
};
