<?php

namespace Database\Seeders;

use App\Enums\BalanceSheetSection;
use App\Enums\ReportStatementType;
use App\Models\ChartOfAccount;
use App\Models\ReportAccountMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds Balance Sheet's account classification (docs/BALANCE_SHEET_DESIGN.md
 * §4) — same declarative-list-plus-skip-and-log shape as
 * ReportAccountMappingSeeder, reusing the identical report_account_mappings
 * table with statement_type = balance_sheet, not a second mapping table.
 */
class BalanceSheetMappingSeeder extends Seeder
{
    /** @var array<string, BalanceSheetSection> */
    protected const BALANCE_SHEET_MAPPINGS = [
        '1100' => BalanceSheetSection::CURRENT_ASSET,       // Cash and Bank
        '112.01' => BalanceSheetSection::CURRENT_ASSET,       // Accounts Receivable
        '1300' => BalanceSheetSection::CURRENT_ASSET,       // Inventory
        '1150' => BalanceSheetSection::CURRENT_LIABILITY,   // Unapplied Customer Payments (liability despite its 1xxx code)
        '210.01' => BalanceSheetSection::CURRENT_LIABILITY,   // Accounts Payable
        '213.01' => BalanceSheetSection::CURRENT_LIABILITY,   // Tax Payable
        '2200' => BalanceSheetSection::CURRENT_LIABILITY,   // Accrued Expenses
        '3000' => BalanceSheetSection::SHARE_CAPITAL,       // Owner's Equity
        '3100' => BalanceSheetSection::RETAINED_EARNINGS,   // Retained Earnings

        // Below: the legacy SkyBiz chart (175 accounts, imported after the baseline
        // above) — never added here, so these ~150 accounts were silently dropped
        // from Balance Sheet entirely, not merely zero. `1199 PENYESUAIAN PIUTANG
        // MIGRASI SKYBIZ` is deliberately excluded (see its migration) — never add it.
        //
        // Cash and Bank — individual branch/bank-account codes under the single
        // seeded 1100 "Cash and Bank" placeholder.
        '1000' => BalanceSheetSection::CURRENT_ASSET,          // KAS
        '1001' => BalanceSheetSection::CURRENT_ASSET,          // KAS SAMARINDA
        '1002' => BalanceSheetSection::CURRENT_ASSET,          // KAS BALIKPAPAN
        '1004' => BalanceSheetSection::CURRENT_ASSET,          // SELISIH KAS KECIL SAMARINDA
        '1005' => BalanceSheetSection::CURRENT_ASSET,          // SELISIH KAS BALIKPAPAN
        // 2026_10_24_000001_restructure_kas_bank_and_aktiva_tetap_into_hierarchy: 1000 (KAS)
        // and 1100 (BANK) are now non-postable parents over these dot-notation children.
        // Dead duplicate twins (1004, 1005, 1103, 102.01.07) are gone.
        '1000.01' => BalanceSheetSection::CURRENT_ASSET,       // KAS SAMARINDA
        '1000.02' => BalanceSheetSection::CURRENT_ASSET,       // KAS BALIKPAPAN
        '1000.03' => BalanceSheetSection::CURRENT_ASSET,       // KAS BESAR SAMARINDA
        '1000.04' => BalanceSheetSection::CURRENT_ASSET,       // KAS BESAR BALIKPAPAN
        '1000.05' => BalanceSheetSection::CURRENT_ASSET,       // SELISIH KAS KECIL SAMARINDA
        '1000.06' => BalanceSheetSection::CURRENT_ASSET,       // SELISIH KAS BALIKPAPAN
        '1100.01' => BalanceSheetSection::CURRENT_ASSET,       // BANK BCA SMD 1312
        '1100.02' => BalanceSheetSection::CURRENT_ASSET,       // BANK OCBC NISP 8466
        '1100.03' => BalanceSheetSection::CURRENT_ASSET,       // BANK OCBC NISP 3383
        '1100.04' => BalanceSheetSection::CURRENT_ASSET,       // BANK MANDIRI 5840
        '1100.05' => BalanceSheetSection::CURRENT_ASSET,       // BANK BCA BTG 8000
        '1100.06' => BalanceSheetSection::CURRENT_ASSET,       // BANK OCBC NISP 6684

        // Receivables (non-trade), advances, and prepaid tax. 112.02/.03/.04 are the renamed
        // 1260/1225/1201 (see 2026_10_09_000002_restructure_piutang_accounts_into_hierarchy) —
        // Piutang Direksi (1215, 112.02.01) is gone. Their legacy-dotted duplicate twins
        // (112.01.02, 112.03.01, 112.09.01) are also gone, per
        // 2026_10_09_000003_delete_untouched_piutang_legacy_duplicate_twins — a code one dot
        // deeper than its sibling (112.01.02 under 112.01) broke the two-level hierarchy's own
        // premise even though no parent_id ever linked them that way.
        '112.02' => BalanceSheetSection::CURRENT_ASSET,        // Piutang Karyawan
        '112.03' => BalanceSheetSection::CURRENT_ASSET,        // Piutang Lain-lain
        '112.04' => BalanceSheetSection::CURRENT_ASSET,        // Cadangan Piutang
        // 2026_10_24_000002_cleanup_uang_muka_duplicate_accounts: 1250 stays flat and
        // directly postable (hardcoded in PaymentEntry's journal lines) — its dead
        // per-vendor sub-ledger (113.01.01/.02, 1251, 1252) and the dead tax-advance
        // twins of 140.01-04 (113.02.01-04) are gone.
        '1250' => BalanceSheetSection::CURRENT_ASSET,          // Advance to Suppliers
        '1400' => BalanceSheetSection::CURRENT_ASSET,          // UANG MUKA PAJAK
        '1401' => BalanceSheetSection::CURRENT_ASSET,          // PPN MASUKAN
        '1402' => BalanceSheetSection::CURRENT_ASSET,          // PPH PASAL 22
        '1403' => BalanceSheetSection::CURRENT_ASSET,          // PPH PASAL 23
        '1404' => BalanceSheetSection::CURRENT_ASSET,          // PPH PASAL 25

        // 2501 "HLL/JUDI JOHANES": named and typed as a Liability (Hutang Lain-Lain)
        // when created, but its only two postings (GJ-00154, GJ-00155, Okt 2026,
        // PBB tax paid on the company's behalf, to be reimbursed) debit the account
        // when cash goes out — the behavior of a receivable, not a payable.
        // Confirmed with the business owner 2026-10-09: treat as Piutang Lain-Lain.
        // Deliberately mapped into the asset section despite account_type=Liability;
        // BalanceSheetService's debit/credit convention check (isDebitNormal()) already
        // flips the sign for exactly this kind of mismatch, so this nets correctly
        // without touching chart_of_accounts.
        '2501' => BalanceSheetSection::CURRENT_ASSET,          // HLL/JUDI JOHANES — functions as a receivable

        // Fixed assets and their accumulated-depreciation contras. 1500 is now a
        // non-postable parent (2026_10_24_000001); its legacy-dotted duplicate twins
        // (121.03.01/.02, 121.04.01/.02) are gone.
        '1500' => BalanceSheetSection::NON_CURRENT_ASSET,          // AKTIVA TETAP
        '1500.01' => BalanceSheetSection::NON_CURRENT_ASSET,       // INVENTARIS KANTOR
        '1500.02' => BalanceSheetSection::NON_CURRENT_ASSET,       // AKUM. PENYUSUTAN INVENTARIS
        '1500.03' => BalanceSheetSection::NON_CURRENT_ASSET,       // KENDARAAN RODA 2 & 4
        '1500.04' => BalanceSheetSection::NON_CURRENT_ASSET,       // AKUM. PENYUSUTAN KENDARAAN RODA 2 & 4

        // Hutang (210.xx) and Hutang Pajak (213.xx) — see
        // 2026_10_09_000004_restructure_hutang_accounts_into_hierarchy. Hutang kpd Direksi
        // (2400, 210.02.01) and the legacy-dotted duplicate twins (210.03.01, 210.09.01,
        // 210.09.02, 219.01.01-04) are gone — same reasoning as the Piutang restructure.
        '210.02' => BalanceSheetSection::CURRENT_LIABILITY,        // Hutang Leasing
        '210.03' => BalanceSheetSection::CURRENT_LIABILITY,        // Hutang Lain-lain
        '210.04' => BalanceSheetSection::CURRENT_LIABILITY,        // Hutang BBM
        '213.02' => BalanceSheetSection::CURRENT_LIABILITY,        // Hutang PPN
        '213.03' => BalanceSheetSection::CURRENT_LIABILITY,        // Hutang PPh Pasal 21
        '213.04' => BalanceSheetSection::CURRENT_LIABILITY,        // Hutang PPh Pasal 23
        '213.05' => BalanceSheetSection::CURRENT_LIABILITY,        // Hutang PPh Pasal 25
    ];

    public function run(): void
    {
        foreach (self::BALANCE_SHEET_MAPPINGS as $code => $section) {
            $account = ChartOfAccount::query()->where('code', $code)->first();

            if (! $account) {
                Log::warning("BalanceSheetMappingSeeder: no Chart of Account found for code {$code} — skipped.");

                continue;
            }

            ReportAccountMapping::query()->firstOrCreate(
                ['chart_of_account_id' => $account->id, 'statement_type' => ReportStatementType::BALANCE_SHEET->value],
                ['section' => $section->value],
            );
        }
    }
}
