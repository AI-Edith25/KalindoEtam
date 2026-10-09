<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Three small fixes requested after reviewing the legacy COA tree again (coa.php) — see project
 * memory project_erp_coa_subaccount_backlog:
 *
 * 1. `112` and `210` were named just "PIUTANG" / "HUTANG" — legacy's own names for those exact
 *    codes are "PIUTANG USAHA DAN LAINNYA" / "HUTANG USAHA DAN LAINNYA". Name-only fix, no code
 *    or relationship change, nothing else depends on these strings.
 * 2. `211 HUTANG BANK` — purely additive (no current-system account ever existed for this; no
 *    hardcoded references anywhere). Confirmed via legacy's own General Ledger that none of the
 *    four bank loan accounts was ever posted to, even in SkyBiz — added anyway per explicit
 *    instruction, as a ready structure for future bank debt.
 *
 * `213.01` (Utang Pajak) was asked about but is NOT touched here — confirmed via the live
 * General Ledger (Rp 49.179.288 real balance) that it's actively used, same account as the old
 * `2100`, hardcoded as the generic tax-posting target in ~15 files. The literal code "213.01"
 * never existed in legacy (only bare "213" did), which is likely what prompted the question —
 * but the account itself, not the code string, is what matters, and it's real.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccount::query()->where('code', '112')->first()?->update(['name' => 'PIUTANG USAHA DAN LAINNYA']);
        ChartOfAccount::query()->where('code', '210')->first()?->update(['name' => 'HUTANG USAHA DAN LAINNYA']);

        $parent = ChartOfAccount::query()->where('code', '211')->first()
            ?? ChartOfAccount::query()->create([
                'code' => '211',
                'name' => 'HUTANG BANK',
                'account_type' => AccountType::LIABILITY,
                'is_active' => true,
            ]);

        $children = [
            '211.01' => 'Hutang Bank BCA',
            '211.02' => 'Hutang Bank Mandiri',
            '211.03' => 'Hutang Bank BNI KMK 4952',
            '211.04' => 'Hutang Bank Niaga 4300',
        ];

        foreach ($children as $code => $name) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'account_type' => AccountType::LIABILITY, 'is_active' => true, 'parent_id' => $parent->id],
            );
        }
    }

    public function down(): void
    {
        ChartOfAccount::query()->where('code', '112')->first()?->update(['name' => 'PIUTANG']);
        ChartOfAccount::query()->where('code', '210')->first()?->update(['name' => 'HUTANG']);
        ChartOfAccount::query()->whereIn('code', ['211', '211.01', '211.02', '211.03', '211.04'])->delete();
    }
};
