<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Builds the first real COA hierarchy (docs reference: project_erp_coa_subaccount_backlog
 * memory) — a non-postable `112 PIUTANG` parent over the four receivable accounts, in the
 * dotted-code shape the business asked for (mirrors the legacy SkyBiz numbering, not a
 * copy of it — legacy's own 112.xx codes are untouched, a separate duplicate-cleanup
 * backlog item).
 *
 * `112.01` is not a fresh code: it IS `1200`, renamed in place (same row, same id, same
 * balance/history) — `1200` was the Accounts Receivable code hardcoded in ~10 files across
 * Invoice/CreditNote/DebitNote/PaymentAllocation/AccountingService/the three report-mapping
 * seeders, all already updated in this same change to read `112.01` instead. Guarded with
 * `where('code', '1200')->first()` (not firstOrFail) so this migration is a no-op on any
 * environment where ChartOfAccountsSeeder already seeded the new scheme directly (fresh
 * installs, test databases) rather than erroring.
 *
 * Piutang Direksi (`1215` and its legacy-dotted twin `112.02.01`) is hard-deleted, not
 * soft-deleted, per explicit instruction — confirmed first to have zero postings in this
 * system (never appeared in any 2026 Trial Balance). forceDelete() bypasses SoftDeletes;
 * the chart_of_account_id foreign key on journal_entry_lines is restrictOnDelete, so this
 * still refuses to run (loudly, not silently) if that confirmation was ever wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            '1200' => '112.01', // Piutang Usaha
            '1260' => '112.02', // Piutang Karyawan
            '1225' => '112.03', // Piutang Lain-lain
            '1201' => '112.04', // Cadangan Piutang
        ];

        foreach ($renames as $oldCode => $newCode) {
            ChartOfAccount::query()->where('code', $oldCode)->first()?->update(['code' => $newCode]);
        }

        $parent = ChartOfAccount::query()->where('code', '112')->first()
            ?? ChartOfAccount::query()->create([
                'code' => '112',
                'name' => 'PIUTANG',
                'account_type' => AccountType::ASSET,
                'is_active' => true,
            ]);

        ChartOfAccount::query()
            ->whereIn('code', array_values($renames))
            ->update(['parent_id' => $parent->id]);

        ChartOfAccount::query()
            ->whereIn('code', ['1215', '112.02.01'])
            ->get()
            ->each(fn (ChartOfAccount $account) => $account->forceDelete());
    }

    public function down(): void
    {
        // Deliberately irreversible — Piutang Direksi was force-deleted (not soft-deleted)
        // per explicit instruction, and the 1200/1260/1225/1201 renames have ~10 application
        // files' worth of code now reading the new codes (see git history for this change).
        // Restore from a database backup if this needs undoing.
    }
};
