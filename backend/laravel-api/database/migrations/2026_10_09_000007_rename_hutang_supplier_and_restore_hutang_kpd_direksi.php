<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Two follow-ups after reviewing legacy usage for Hutang Supplier, Hutang kpd Direksi, and
 * Hutang Ekspedisi (see project memory project_erp_coa_subaccount_backlog):
 *
 * 1. `210.01` renamed from "Utang Usaha" to "Hutang Supplier" — matches legacy's own
 *    leaf-level name for this exact account (`210.01.01 HUTANG SUPPLIER`, Rp 27,6 miliar
 *    lifetime in legacy). Name-only, same row/id/history.
 * 2. `210.05 Hutang kpd Direksi` added back under `210`, per explicit request — this account
 *    was hard-deleted in 2026_10_09_000004 (confirmed zero postings in this system at the
 *    time). It is NOT restored: that row's id and any trace of it are gone for good. This is a
 *    fresh account starting at zero. The real multi-year history (legacy confirmed it's a
 *    director loan to the company, Rp 3,77 miliar, going back to at least 2021) lives only in
 *    legacy SkyBiz.
 *
 * Hutang Ekspedisi was also asked about — confirmed via the live Chart of Accounts that no such
 * account exists in this system (never carried over from legacy, where it also had zero
 * lifetime postings). Nothing to delete; not touched by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccount::query()->where('code', '210.01')->first()?->update(['name' => 'Hutang Supplier']);

        $parent = ChartOfAccount::query()->where('code', '210')->firstOrFail();

        ChartOfAccount::query()->firstOrCreate(
            ['code' => '210.05'],
            ['name' => 'Hutang kpd Direksi', 'account_type' => AccountType::LIABILITY, 'is_active' => true, 'parent_id' => $parent->id],
        );
    }

    public function down(): void
    {
        ChartOfAccount::query()->where('code', '210.01')->first()?->update(['name' => 'Utang Usaha']);
        ChartOfAccount::query()->where('code', '210.05')->delete();
    }
};
