<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Fifth group from project_erp_coa_subaccount_backlog — Uang Muka. Unlike Piutang/Hutang/
 * Kas & Bank/Aktiva Tetap, this is cleanup only, no new hierarchy:
 *
 * - Uang Muka Pajak already has a hierarchy (`140` parent, built earlier) — only its dead
 *   legacy-dotted twins (113.02.01-04) needed removing.
 * - Uang Muka Pembelian's live account, `1250 Advance to Suppliers`, is hardcoded as a
 *   direct posting target in PaymentEntry::journalLines()/mixedJournalLines() (Mixed and
 *   plain-supplier payment flows both debit '1250' literally) — turning it into a
 *   non-postable parent would break both flows under JournalEntryService's header-account
 *   guardrail. Its per-vendor children (113.01.01/.02, 1251, 1252) were a sub-ledger
 *   breakdown that was never actually used (zero lifetime turnover, confirmed below) —
 *   dead placeholders, not a feature to build a hierarchy around. So `1250` stays exactly
 *   as it is today: flat, active, directly postable.
 *
 * All nine accounts deleted here showed zero debit and zero credit, their entire
 * lifetime, in this system's Print Ledger (checked 2026-10-24) — but that report only
 * sums non-deleted Journal Entries, while a soft-deleted/voided entry's
 * journal_entry_lines rows still physically exist and still hold the FK. The sibling
 * 2026_10_24_000001 migration hit exactly that on first deploy
 * (journal_entry_lines_chart_of_account_id_foreign violation on forceDelete), so this one
 * uses soft `delete()` too, matching the ticket's own explicit instruction ("JANGAN
 * menghapus data akun duplikat secara permanen — nonaktifkan saja") — it can never hit
 * this FK. One of the nine (113.02.04 PPH PASAL 22) also carried a data error — typed
 * Liability while its surviving `140.02` twin is correctly Asset — moot once deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccount::query()
            ->whereIn('code', [
                '113.02.01', '113.02.02', '113.02.03', '113.02.04', // dead twins of 140.01-04
                '113.01', '113.01.01', '113.01.02', // dead twins of 1250/1251/1252
                '1251', '1252', // unused per-vendor sub-ledger, never posted to
            ])
            ->delete();
    }

    public function down(): void
    {
        // Deliberately irreversible — the dead-duplicate accounts aren't automatically
        // restored. They can be manually if needed
        // (ChartOfAccount::withTrashed()->where(...)->restore()) — soft delete() was used,
        // not forceDelete(), so nothing here is actually unrecoverable.
    }
};
