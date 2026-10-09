<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Follow-up to 2026_10_09_000002 — that migration deliberately left the legacy-dotted duplicate
 * twins of 112.02/112.03/112.04 untouched (112.03.01, 112.09.01, and 112.01.02 respectively),
 * treating them as the separate 59-pair cleanup in project memory
 * project_erp_coa_duplicate_codes. Wrong call: a dotted code one level deeper than its sibling
 * (112.01.02 reads as a child of 112.01, not as a sibling of 112.04) breaks the two-level
 * hierarchy's own premise, even though no parent_id ever linked them that way.
 *
 * Confirmed via the General Ledger report (full 2020-2026 range) before writing this: all three
 * have zero debit and zero credit, their entire lifetime, in this system — same bar used for
 * hard-deleting Piutang Direksi. forceDelete() bypasses SoftDeletes; the restrictOnDelete FK on
 * journal_entry_lines still refuses the delete (loudly) if that was ever wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccount::query()
            ->whereIn('code', ['112.01.02', '112.03.01', '112.09.01'])
            ->get()
            ->each(fn (ChartOfAccount $account) => $account->forceDelete());
    }

    public function down(): void
    {
        // Deliberately irreversible — see 2026_10_09_000002's down(). Restore from a database
        // backup if this needs undoing.
    }
};
