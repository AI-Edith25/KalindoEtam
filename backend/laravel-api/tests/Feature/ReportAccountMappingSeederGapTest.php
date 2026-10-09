<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\BalanceSheetSection;
use App\Enums\CashFlowSection;
use App\Enums\ProfitLossSection;
use App\Enums\ReportStatementType;
use App\Models\ChartOfAccount;
use App\Models\ReportAccountMapping;
use Database\Seeders\BalanceSheetMappingSeeder;
use Database\Seeders\CashFlowMappingSeeder;
use Database\Seeders\ReportAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-10-09 fix: ~150 legacy SkyBiz accounts (code,
 * name, account_type already existed in chart_of_accounts) had no row in any of
 * the three report_account_mappings seeders, so Balance Sheet / Income Statement /
 * Cash Flow silently excluded them — e.g. BIAYA GAJI and KAS SAMARINDA never
 * appeared despite carrying real balances. See project memory
 * project_erp_coa_report_mapping_gap.md.
 */
class ReportAccountMappingSeederGapTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_previously_unmapped_operating_expense_account_is_now_mapped(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '6010', 'name' => 'BIAYA GAJI', 'account_type' => AccountType::EXPENSE]);

        $this->seed(ReportAccountMappingSeeder::class);

        $mapping = ReportAccountMapping::query()
            ->where('chart_of_account_id', $account->id)
            ->where('statement_type', ReportStatementType::PROFIT_LOSS->value)
            ->sole();

        $this->assertSame(ProfitLossSection::OPERATING_EXPENSE->value, $mapping->section);
    }

    public function test_a_previously_unmapped_cogs_account_is_now_mapped(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '5400', 'name' => 'BIAYA PENGIRIMAN/EKSPEDISI (HPP)', 'account_type' => AccountType::EXPENSE]);

        $this->seed(ReportAccountMappingSeeder::class);

        $mapping = ReportAccountMapping::query()
            ->where('chart_of_account_id', $account->id)
            ->where('statement_type', ReportStatementType::PROFIT_LOSS->value)
            ->sole();

        $this->assertSame(ProfitLossSection::COST_OF_GOODS_SOLD->value, $mapping->section);
    }

    public function test_a_previously_unmapped_cash_account_is_mapped_on_balance_sheet_and_cash_flow(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '1001', 'name' => 'KAS SAMARINDA', 'account_type' => AccountType::ASSET, 'is_cash_bank' => true]);

        $this->seed(BalanceSheetMappingSeeder::class);
        $this->seed(CashFlowMappingSeeder::class);

        $balanceSheetMapping = ReportAccountMapping::query()
            ->where('chart_of_account_id', $account->id)
            ->where('statement_type', ReportStatementType::BALANCE_SHEET->value)
            ->sole();
        $this->assertSame(BalanceSheetSection::CURRENT_ASSET->value, $balanceSheetMapping->section);

        $cashFlowMapping = ReportAccountMapping::query()
            ->where('chart_of_account_id', $account->id)
            ->where('statement_type', ReportStatementType::CASH_FLOW->value)
            ->sole();
        $this->assertSame(CashFlowSection::CASH_AND_EQUIVALENTS->value, $cashFlowMapping->section);
    }

    public function test_the_skybiz_migration_suspense_account_stays_unmapped(): void
    {
        // The migration that introduces this account (2026_10_03_000007) already
        // creates it via firstOrCreate — it runs for every RefreshDatabase test.
        $account = ChartOfAccount::query()->where('code', '1199')->sole();

        $this->seed(ReportAccountMappingSeeder::class);
        $this->seed(BalanceSheetMappingSeeder::class);
        $this->seed(CashFlowMappingSeeder::class);

        $this->assertSame(0, ReportAccountMapping::query()->where('chart_of_account_id', $account->id)->count());
    }

    public function test_account_2501_is_mapped_as_a_receivable_despite_its_liability_account_type(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '2501', 'name' => 'HLL/JUDI JOHANES', 'account_type' => AccountType::LIABILITY]);

        $this->seed(BalanceSheetMappingSeeder::class);
        $this->seed(CashFlowMappingSeeder::class);

        $balanceSheetMapping = ReportAccountMapping::query()
            ->where('chart_of_account_id', $account->id)
            ->where('statement_type', ReportStatementType::BALANCE_SHEET->value)
            ->sole();
        $this->assertSame(BalanceSheetSection::CURRENT_ASSET->value, $balanceSheetMapping->section);

        $cashFlowMapping = ReportAccountMapping::query()
            ->where('chart_of_account_id', $account->id)
            ->where('statement_type', ReportStatementType::CASH_FLOW->value)
            ->sole();
        $this->assertSame(CashFlowSection::OPERATING_ADJUSTMENT->value, $cashFlowMapping->section);
    }

    public function test_seeders_are_idempotent_when_rerun(): void
    {
        ChartOfAccount::query()->create(['code' => '6010', 'name' => 'BIAYA GAJI', 'account_type' => AccountType::EXPENSE]);

        $this->seed(ReportAccountMappingSeeder::class);
        $this->seed(ReportAccountMappingSeeder::class);

        $this->assertSame(1, ReportAccountMapping::query()->where('statement_type', ReportStatementType::PROFIT_LOSS->value)->count());
    }
}
