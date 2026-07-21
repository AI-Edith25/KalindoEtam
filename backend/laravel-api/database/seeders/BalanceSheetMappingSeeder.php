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
