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
        '1200' => BalanceSheetSection::CURRENT_ASSET,       // Accounts Receivable
        '1300' => BalanceSheetSection::CURRENT_ASSET,       // Inventory
        '1150' => BalanceSheetSection::CURRENT_LIABILITY,   // Unapplied Customer Payments (liability despite its 1xxx code)
        '2000' => BalanceSheetSection::CURRENT_LIABILITY,   // Accounts Payable
        '2100' => BalanceSheetSection::CURRENT_LIABILITY,   // Tax Payable
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
        '101.01.01' => BalanceSheetSection::CURRENT_ASSET,     // KAS BESAR SAMARINDA
        '101.01.03' => BalanceSheetSection::CURRENT_ASSET,     // KAS BESAR BALIKPAPAN
        '101.03.01' => BalanceSheetSection::CURRENT_ASSET,     // SELISIH KAS KECIL SAMARINDA
        '101.03.02' => BalanceSheetSection::CURRENT_ASSET,     // SELISIH KAS BALIKPAPAN
        '102.01.05' => BalanceSheetSection::CURRENT_ASSET,     // BANK OCBC NISP 8466
        '102.01.06' => BalanceSheetSection::CURRENT_ASSET,     // BANK OCBC NISP 3383
        '102.01.07' => BalanceSheetSection::CURRENT_ASSET,     // BANK OCBC NISP 6684
        '102.02.01' => BalanceSheetSection::CURRENT_ASSET,     // BANK BCA BTG 8000
        '1101' => BalanceSheetSection::CURRENT_ASSET,          // BANK BCA SMD 1312
        '1103' => BalanceSheetSection::CURRENT_ASSET,          // BANK BCA BTG 8000
        '1104' => BalanceSheetSection::CURRENT_ASSET,          // BANK MANDIRI 5840
        '1106' => BalanceSheetSection::CURRENT_ASSET,          // BANK OCBC NISP 6684

        // Receivables (non-trade), advances, and prepaid tax.
        '112.01.02' => BalanceSheetSection::CURRENT_ASSET,     // CADANGAN PIUTANG
        '112.02.01' => BalanceSheetSection::CURRENT_ASSET,     // PIUTANG DIREKSI
        '112.03.01' => BalanceSheetSection::CURRENT_ASSET,     // PIUTANG KARYAWAN
        '112.09.01' => BalanceSheetSection::CURRENT_ASSET,     // PIUTANG LAIN-LAIN
        '1201' => BalanceSheetSection::CURRENT_ASSET,          // CADANGAN PIUTANG
        '1215' => BalanceSheetSection::CURRENT_ASSET,          // PIUTANG DIREKSI
        '1225' => BalanceSheetSection::CURRENT_ASSET,          // PIUTANG LAIN-LAIN
        '1260' => BalanceSheetSection::CURRENT_ASSET,          // PIUTANG KARYAWAN
        '1250' => BalanceSheetSection::CURRENT_ASSET,          // Advance to Suppliers
        '113.01.01' => BalanceSheetSection::CURRENT_ASSET,     // UMP/PT CONCH
        '113.01.02' => BalanceSheetSection::CURRENT_ASSET,     // UMP/KENDARAAN
        '1251' => BalanceSheetSection::CURRENT_ASSET,          // UMP/PT CONCH
        '1252' => BalanceSheetSection::CURRENT_ASSET,          // UMP/KENDARAAN
        '113.02.01' => BalanceSheetSection::CURRENT_ASSET,     // PPN MASUKAN
        '113.02.02' => BalanceSheetSection::CURRENT_ASSET,     // PPH PASAL 25
        '113.02.03' => BalanceSheetSection::CURRENT_ASSET,     // PPH PASAL 23
        '113.02.04' => BalanceSheetSection::CURRENT_ASSET,     // PPH PASAL 22
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

        // Fixed assets and their accumulated-depreciation contras.
        '121.03.01' => BalanceSheetSection::NON_CURRENT_ASSET,     // KENDARAAN RODA 2 & 4
        '121.03.02' => BalanceSheetSection::NON_CURRENT_ASSET,     // AKUM. PENYUSUTAN KENDARAAN RODA 2 & 4
        '121.04.01' => BalanceSheetSection::NON_CURRENT_ASSET,     // INVENTARIS KANTOR
        '121.04.02' => BalanceSheetSection::NON_CURRENT_ASSET,     // AKUM. PENYUSUTAN INVENTARIS
        '1500' => BalanceSheetSection::NON_CURRENT_ASSET,          // AKTIVA TETAP
        '1501' => BalanceSheetSection::NON_CURRENT_ASSET,          // INVENTARIS KANTOR
        '1502' => BalanceSheetSection::NON_CURRENT_ASSET,          // AKUM. PENYUSUTAN INVENTARIS
        '1503' => BalanceSheetSection::NON_CURRENT_ASSET,          // KENDARAAN RODA 2 & 4
        '1504' => BalanceSheetSection::NON_CURRENT_ASSET,          // AKUM. PENYUSUTAN KENDARAAN RODA 2 & 4

        // Non-trade payables and detailed tax payables — same Current Liabilities
        // bucket the legacy system itself used for these (B70/B72).
        '210.02.01' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG KPD DIREKSI
        '210.03.01' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG LEASING
        '210.09.01' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG LAIN-LAIN
        '210.09.02' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG BBM
        '2101' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG PPN
        '2102' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG PPH PASAL 21
        '2103' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG PPH PASAL 23
        '2104' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG PPH PASAL 25
        '219.01.01' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG PPN
        '219.01.02' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG PPH PASAL 23
        '219.01.03' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG PPH PASAL 21
        '219.01.04' => BalanceSheetSection::CURRENT_LIABILITY,     // HUTANG PPH PASAL 25
        '2300' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG LEASING
        '2400' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG KPD DIREKSI
        '2500' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG LAIN-LAIN
        '2600' => BalanceSheetSection::CURRENT_LIABILITY,          // HUTANG BBM
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
