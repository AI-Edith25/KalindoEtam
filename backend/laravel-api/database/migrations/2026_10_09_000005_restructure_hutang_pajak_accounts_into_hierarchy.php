<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Hutang Pajak's own hierarchy — deliberately separate from `210 HUTANG`
 * (2026_10_09_000004), reusing legacy's own standalone `213 HUTANG PAJAK` code as the new
 * parent. See project memory project_erp_coa_subaccount_backlog.
 *
 * `213.01` is not a fresh code: it IS `2100`, renamed in place (same row, same id, same
 * balance/history) — `2100` was the generic Tax Payable code hardcoded in ~15 files across
 * Invoice/CreditNote/DebitNote/PurchaseInvoice/PurchaseReturn/AccountingService/both journal
 * exports, all already updated in this same change to read `213.01` instead. It stays named
 * "Utang Pajak" rather than renamed to one specific tax type — every one of those call sites
 * posts it generically for any taxable transaction, not specifically PPN or PPh.
 *
 * `213.02`-`213.05` (PPN, PPh 21/23/25) are the renamed `2101`-`2104` — unused in this system
 * (confirmed via the General Ledger report's full 2020-2026 range) but real historical
 * categories in legacy SkyBiz (219.01.01-04, e.g. PPN alone carried Rp 5.8 miliar there) that
 * the business may want to start using. The legacy-dotted twins (219.01.01-04) are hard-deleted
 * — same "avoid a code that reads as a grandchild" reasoning as 2026_10_09_000003 — confirmed
 * zero balance in this system before deleting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            '2100' => '213.01', // Utang Pajak (general)
            '2101' => '213.02', // Hutang PPN
            '2102' => '213.03', // Hutang PPh Pasal 21
            '2103' => '213.04', // Hutang PPh Pasal 23
            '2104' => '213.05', // Hutang PPh Pasal 25
        ];

        foreach ($renames as $oldCode => $newCode) {
            ChartOfAccount::query()->where('code', $oldCode)->first()?->update(['code' => $newCode]);
        }

        $parent = ChartOfAccount::query()->where('code', '213')->first()
            ?? ChartOfAccount::query()->create([
                'code' => '213',
                'name' => 'HUTANG PAJAK',
                'account_type' => AccountType::LIABILITY,
                'is_active' => true,
            ]);

        ChartOfAccount::query()
            ->whereIn('code', array_values($renames))
            ->update(['parent_id' => $parent->id]);

        ChartOfAccount::query()
            ->whereIn('code', ['219.01.01', '219.01.02', '219.01.03', '219.01.04'])
            ->get()
            ->each(fn (ChartOfAccount $account) => $account->forceDelete());
    }

    public function down(): void
    {
        // Deliberately irreversible — see 2026_10_09_000002's down(). Restore from a database
        // backup if this needs undoing.
    }
};
