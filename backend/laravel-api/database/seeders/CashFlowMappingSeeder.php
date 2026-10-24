<?php

namespace Database\Seeders;

use App\Enums\CashFlowSection;
use App\Enums\ReportStatementType;
use App\Models\ChartOfAccount;
use App\Models\ReportAccountMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds Cash Flow's account classification (docs/CASH_FLOW_DESIGN.md §5) —
 * same declarative-list-plus-skip-and-log shape as
 * BalanceSheetMappingSeeder/ReportAccountMappingSeeder, reusing the
 * identical report_account_mappings table with statement_type = cash_flow.
 * `3100 Retained Earnings` is deliberately omitted — it is a derived
 * Balance Sheet figure (always 0 pre-Period-Closing), and mapping it would
 * double-count money already reflected in Net Profit.
 */
class CashFlowMappingSeeder extends Seeder
{
    /** @var array<string, CashFlowSection> */
    protected const CASH_FLOW_MAPPINGS = [
        '1100' => CashFlowSection::CASH_AND_EQUIVALENTS, // Cash and Bank
        '112.01' => CashFlowSection::OPERATING_ADJUSTMENT,  // Accounts Receivable
        '1300' => CashFlowSection::OPERATING_ADJUSTMENT,  // Inventory
        '1150' => CashFlowSection::OPERATING_ADJUSTMENT,  // Unapplied Customer Payments
        '210.01' => CashFlowSection::OPERATING_ADJUSTMENT,  // Accounts Payable
        '213.01' => CashFlowSection::OPERATING_ADJUSTMENT,  // Tax Payable
        '2200' => CashFlowSection::OPERATING_ADJUSTMENT,  // Accrued Expenses
        '3000' => CashFlowSection::FINANCING_ACTIVITY,    // Owner's Equity

        // Below: the legacy SkyBiz chart (175 accounts) — same Balance Sheet
        // accounts BalanceSheetMappingSeeder now covers, translated into Cash
        // Flow's own vocabulary. Revenue/Expense accounts are deliberately never
        // mapped here (see class doc) — only Asset/Liability/Equity accounts need
        // a Cash Flow section. `1199` stays excluded, same as everywhere else.
        '1000' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1001' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1002' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1004' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1005' => CashFlowSection::CASH_AND_EQUIVALENTS,
        // 2026_10_24_000001_restructure_kas_bank_and_aktiva_tetap_into_hierarchy: 1000/1100
        // are now non-postable parents; dead duplicate twins (1004, 1005, 1103, 102.01.07)
        // are gone.
        '1000.01' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1000.02' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1000.03' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1000.04' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1000.05' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1000.06' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1100.01' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1100.02' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1100.03' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1100.04' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1100.05' => CashFlowSection::CASH_AND_EQUIVALENTS,
        '1100.06' => CashFlowSection::CASH_AND_EQUIVALENTS,

        // 112.02/.03/.04 are the renamed 1260/1225/1201 (see
        // 2026_10_09_000002_restructure_piutang_accounts_into_hierarchy); Piutang Direksi
        // (1215, 112.02.01) is gone, and so are its legacy-dotted duplicate twins
        // (112.01.02, 112.03.01, 112.09.01) — see
        // 2026_10_09_000003_delete_untouched_piutang_legacy_duplicate_twins.
        '112.02' => CashFlowSection::OPERATING_ADJUSTMENT,
        '112.03' => CashFlowSection::OPERATING_ADJUSTMENT,
        '112.04' => CashFlowSection::OPERATING_ADJUSTMENT,
        // 2026_10_24_000002_cleanup_uang_muka_duplicate_accounts: 1250 stays flat/postable
        // (hardcoded in PaymentEntry); its dead per-vendor sub-ledger and the dead tax-advance
        // twins of 140.01-04 are gone.
        '1250' => CashFlowSection::OPERATING_ADJUSTMENT,
        '1400' => CashFlowSection::OPERATING_ADJUSTMENT,
        '1401' => CashFlowSection::OPERATING_ADJUSTMENT,
        '1402' => CashFlowSection::OPERATING_ADJUSTMENT,
        '1403' => CashFlowSection::OPERATING_ADJUSTMENT,
        '1404' => CashFlowSection::OPERATING_ADJUSTMENT,

        // 2501 "HLL/JUDI JOHANES" — functions as a receivable, see
        // BalanceSheetMappingSeeder for the full explanation.
        '2501' => CashFlowSection::OPERATING_ADJUSTMENT,

        // 1500 is now a non-postable parent (2026_10_24_000001); legacy-dotted duplicate
        // twins (121.03.01/.02, 121.04.01/.02) are gone.
        '1500' => CashFlowSection::INVESTING_ACTIVITY,
        '1500.01' => CashFlowSection::INVESTING_ACTIVITY,
        '1500.02' => CashFlowSection::INVESTING_ACTIVITY,
        '1500.03' => CashFlowSection::INVESTING_ACTIVITY,
        '1500.04' => CashFlowSection::INVESTING_ACTIVITY,

        // Hutang (210.xx) and Hutang Pajak (213.xx) — see
        // 2026_10_09_000004_restructure_hutang_accounts_into_hierarchy. Hutang kpd Direksi and
        // the legacy-dotted duplicate twins are gone, same reasoning as the Piutang restructure.
        '210.02' => CashFlowSection::OPERATING_ADJUSTMENT,
        '210.03' => CashFlowSection::OPERATING_ADJUSTMENT,
        '210.04' => CashFlowSection::OPERATING_ADJUSTMENT,
        '213.02' => CashFlowSection::OPERATING_ADJUSTMENT,
        '213.03' => CashFlowSection::OPERATING_ADJUSTMENT,
        '213.04' => CashFlowSection::OPERATING_ADJUSTMENT,
        '213.05' => CashFlowSection::OPERATING_ADJUSTMENT,
    ];

    public function run(): void
    {
        foreach (self::CASH_FLOW_MAPPINGS as $code => $section) {
            $account = ChartOfAccount::query()->where('code', $code)->first();

            if (! $account) {
                Log::warning("CashFlowMappingSeeder: no Chart of Account found for code {$code} — skipped.");

                continue;
            }

            ReportAccountMapping::query()->firstOrCreate(
                ['chart_of_account_id' => $account->id, 'statement_type' => ReportStatementType::CASH_FLOW->value],
                ['section' => $section->value],
            );
        }
    }
}
