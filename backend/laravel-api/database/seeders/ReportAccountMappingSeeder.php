<?php

namespace Database\Seeders;

use App\Enums\ProfitLossSection;
use App\Enums\ReportStatementType;
use App\Models\ChartOfAccount;
use App\Models\ReportAccountMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds Profit & Loss's account classification (docs/PROFIT_LOSS_DESIGN.md
 * §4) — one declarative list here, not scattered literals in application
 * code. Looks accounts up by code without failing the whole run if one is
 * missing (a future Chart of Accounts edit shouldn't break this seeder);
 * a missing code is logged instead, the same visibility principle
 * ProfitLossService applies to unmapped accounts at report time.
 */
class ReportAccountMappingSeeder extends Seeder
{
    /** @var array<string, ProfitLossSection> */
    protected const PROFIT_LOSS_MAPPINGS = [
        '4000' => ProfitLossSection::REVENUE,             // Sales Revenue
        '4050' => ProfitLossSection::REVENUE,              // Sales Returns and Allowances (contra)
        '4100' => ProfitLossSection::OTHER_INCOME,         // Other Income
        '4900' => ProfitLossSection::REVENUE,              // Discount Given (contra)
        '5000' => ProfitLossSection::COST_OF_GOODS_SOLD,   // Cost of Goods Sold
        '5100' => ProfitLossSection::COST_OF_GOODS_SOLD,   // Purchase Expense
        '6000' => ProfitLossSection::OPERATING_EXPENSE,    // Operating Expenses
    ];

    public function run(): void
    {
        foreach (self::PROFIT_LOSS_MAPPINGS as $code => $section) {
            $account = ChartOfAccount::query()->where('code', $code)->first();

            if (! $account) {
                Log::warning("ReportAccountMappingSeeder: no Chart of Account found for code {$code} — skipped.");

                continue;
            }

            ReportAccountMapping::query()->firstOrCreate(
                ['chart_of_account_id' => $account->id, 'statement_type' => ReportStatementType::PROFIT_LOSS->value],
                ['section' => $section->value],
            );
        }
    }
}
