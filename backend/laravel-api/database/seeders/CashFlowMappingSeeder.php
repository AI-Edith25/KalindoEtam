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
        '1200' => CashFlowSection::OPERATING_ADJUSTMENT,  // Accounts Receivable
        '1300' => CashFlowSection::OPERATING_ADJUSTMENT,  // Inventory
        '1150' => CashFlowSection::OPERATING_ADJUSTMENT,  // Unapplied Customer Payments
        '2000' => CashFlowSection::OPERATING_ADJUSTMENT,  // Accounts Payable
        '2100' => CashFlowSection::OPERATING_ADJUSTMENT,  // Tax Payable
        '2200' => CashFlowSection::OPERATING_ADJUSTMENT,  // Accrued Expenses
        '3000' => CashFlowSection::FINANCING_ACTIVITY,    // Owner's Equity
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
