<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Hutang's version of 2026_10_09_000002 (Piutang) — see project memory
 * project_erp_coa_subaccount_backlog. `210 HUTANG` (new, non-postable parent) over the four
 * payable accounts. Deliberately NOT merged with Hutang Bank (211.xx, never used even in legacy,
 * has no current-system account at all) or Hutang Pajak (own hierarchy, see the next migration)
 * — legacy itself keeps Trade/Other Payables, Bank Borrowings, and tax liabilities as separate
 * top-level Financial Categories (B70/B20/B72/B80), a real accounting distinction, not an
 * arbitrary split.
 *
 * `210.01` is not a fresh code: it IS `2000`, renamed in place (same row, same id, same
 * balance/history) — `2000` was the Accounts Payable code hardcoded in ~15 files across
 * PurchaseInvoice/PurchaseReturn/PaymentEntryAllocation/AccountingService/PurchaseJournalExport
 * and the report-mapping seeders, all already updated in this same change to read `210.01`
 * instead. Guarded with `where('code', '2000')->first()` (not firstOrFail) so this migration is
 * a no-op on any environment where ChartOfAccountsSeeder already seeded the new scheme directly.
 *
 * Hutang kpd Direksi (`2400` and its legacy-dotted twin `210.02.01`) is hard-deleted, not
 * soft-deleted, per explicit instruction — same decision and same confirmation (zero postings
 * anywhere in this system, checked via the General Ledger report's full 2020-2026 range) as
 * Piutang Direksi. The three OTHER legacy-dotted duplicate twins (210.03.01, 210.09.01,
 * 210.09.02) are hard-deleted too, for the same reason the Piutang restructure's follow-up
 * migration (2026_10_09_000003) deleted its own leftovers: a code one dot deeper than its
 * sibling reads as a grandchild, breaking the two-level hierarchy's own premise — confirmed
 * zero balance before deleting, same bar as everything else here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            '2000' => '210.01', // Hutang Usaha
            '2300' => '210.02', // Hutang Leasing
            '2500' => '210.03', // Hutang Lain-lain
            '2600' => '210.04', // Hutang BBM
        ];

        foreach ($renames as $oldCode => $newCode) {
            ChartOfAccount::query()->where('code', $oldCode)->first()?->update(['code' => $newCode]);
        }

        $parent = ChartOfAccount::query()->where('code', '210')->first()
            ?? ChartOfAccount::query()->create([
                'code' => '210',
                'name' => 'HUTANG',
                'account_type' => AccountType::LIABILITY,
                'is_active' => true,
            ]);

        ChartOfAccount::query()
            ->whereIn('code', array_values($renames))
            ->update(['parent_id' => $parent->id]);

        ChartOfAccount::query()
            ->whereIn('code', ['2400', '210.02.01', '210.03.01', '210.09.01', '210.09.02'])
            ->get()
            ->each(fn (ChartOfAccount $account) => $account->forceDelete());
    }

    public function down(): void
    {
        // Deliberately irreversible — see 2026_10_09_000002's down(). Restore from a database
        // backup if this needs undoing.
    }
};
