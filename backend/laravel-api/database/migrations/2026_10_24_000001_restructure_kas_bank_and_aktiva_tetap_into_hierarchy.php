<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Third and fourth groups from project_erp_coa_subaccount_backlog — Kas & Bank (per
 * cabang/rekening) and Aktiva Tetap, applying the same reuse-the-live-code-as-master,
 * dot-notation-children pattern as Piutang/Hutang.
 *
 * Unlike Piutang/Hutang, several "duplicate-looking" pairs here turned out NOT to be
 * duplicates — e.g. `1001 KAS SAMARINDA` and `101.01.01 KAS BESAR SAMARINDA` both carry
 * real, different-amount lifetime turnover in this system's Print Ledger, so both survive
 * as separate children. Only pairs with the exact same name, where one side is dead,
 * are merged. Master choice ($code kept) is whichever side has lifetime turnover in this
 * system's Print Ledger (checked 2026-10-24); where both sides were zero
 * (`1106`/`102.01.07` BANK OCBC NISP 6684), the clean 4-digit code was kept as master —
 * legacy clouderp.my could not be cross-checked (no login available this session).
 *
 * `1000 KAS` and `1100 BANK` are reused as the two parents — already-existing flat
 * top-level accounts with zero lifetime turnover of their own and no hardcoded posting
 * reference anywhere in app/ (confirmed via grep before writing this), so turning them
 * into non-postable headers breaks nothing. `1500 AKTIVA TETAP` likewise.
 *
 * The four duplicate twins (1004, 1005, 1103, 102.01.07) and the four Aktiva Tetap
 * legacy-dotted twins (121.03.01, 121.03.02, 121.04.01, 121.04.02) showed zero debit and
 * zero credit, their entire lifetime, in this system's Print Ledger — but that report
 * only sums non-deleted Journal Entries, while journal_entry_lines rows from a
 * soft-deleted/voided entry still physically exist and still hold the FK. One of these
 * eight hit exactly that on first deploy (`journal_entry_lines_chart_of_account_id_foreign`
 * violation on forceDelete) — the Print Ledger check wasn't sufficient proof of "never
 * used". Soft `delete()` instead, matching the ticket's own explicit instruction
 * ("JANGAN menghapus data akun duplikat secara permanen — nonaktifkan saja") — it can
 * never hit this FK (the row stays, journal_entry_lines keeps pointing at a valid id),
 * and chart_of_accounts' own code-uniqueness check already excludes soft-deleted rows
 * (`whereNull('deleted_at')` in Store/UpdateChartOfAccountRequest), so the freed-up
 * dotted codes stay available for reuse exactly like the prior unused-cash/bank cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kasRenames = [
            '1001' => '1000.01', // KAS SAMARINDA
            '1002' => '1000.02', // KAS BALIKPAPAN
            '101.01.01' => '1000.03', // KAS BESAR SAMARINDA
            '101.01.03' => '1000.04', // KAS BESAR BALIKPAPAN
            '101.03.01' => '1000.05', // SELISIH KAS KECIL SAMARINDA (live twin, replaces dead 1004)
            '101.03.02' => '1000.06', // SELISIH KAS BALIKPAPAN (live twin, replaces dead 1005)
        ];

        $bankRenames = [
            '1101' => '1100.01', // BANK BCA SMD 1312
            '102.01.05' => '1100.02', // BANK OCBC NISP 8466
            '102.01.06' => '1100.03', // BANK OCBC NISP 3383
            '1104' => '1100.04', // BANK MANDIRI 5840
            '102.02.01' => '1100.05', // BANK BCA BTG 8000 (live twin, replaces dead 1103)
            '1106' => '1100.06', // BANK OCBC NISP 6684 (both sides zero, clean code kept, replaces 102.01.07)
        ];

        $aktivaTetapRenames = [
            '1501' => '1500.01', // INVENTARIS KANTOR
            '1502' => '1500.02', // AKUM. PENYUSUTAN INVENTARIS
            '1503' => '1500.03', // KENDARAAN RODA 2 & 4
            '1504' => '1500.04', // AKUM. PENYUSUTAN KENDARAAN RODA 2 & 4
        ];

        $this->restructureGroup($kasRenames, '1000', 'KAS');
        $this->restructureGroup($bankRenames, '1100', 'BANK');
        $this->restructureGroup($aktivaTetapRenames, '1500', 'AKTIVA TETAP');

        ChartOfAccount::query()
            ->whereIn('code', ['1004', '1005', '1103', '102.01.07', '121.03.01', '121.03.02', '121.04.01', '121.04.02'])
            ->delete();
    }

    /**
     * No-op unless at least one of this group's legacy codes actually exists — otherwise
     * a fresh install or test database (neither seeds nor migrations ever created these
     * production-only legacy-imported codes) would get a speculative '1000'/'1100'/'1500'
     * parent row created purely as a side effect, colliding with any test that reuses that
     * same generic code as its own unrelated fixture (e.g. '1100' is reused everywhere in
     * this suite as a throwaway "some cash/bank account" code).
     */
    private function restructureGroup(array $renames, string $parentCode, string $parentName): void
    {
        if (ChartOfAccount::query()->whereIn('code', array_keys($renames))->doesntExist()) {
            return;
        }

        foreach ($renames as $oldCode => $newCode) {
            ChartOfAccount::query()->where('code', $oldCode)->first()?->update(['code' => $newCode]);
        }

        $parent = ChartOfAccount::query()->where('code', $parentCode)->first()
            ?? ChartOfAccount::query()->create(['code' => $parentCode, 'name' => $parentName, 'account_type' => AccountType::ASSET, 'is_active' => true]);

        ChartOfAccount::query()->whereIn('code', array_values($renames))->update(['parent_id' => $parent->id]);
    }

    public function down(): void
    {
        // Deliberately irreversible — the renames and parent_id assignments aren't undone,
        // and the renamed codes now have report-mapping seeder entries pointing at the new
        // strings. The eight soft-deleted duplicates can be restored manually
        // (ChartOfAccount::withTrashed()->where(...)->restore()) if that's ever needed —
        // unlike the Piutang/Hutang hard-deletes, nothing here is actually unrecoverable.
    }
};
