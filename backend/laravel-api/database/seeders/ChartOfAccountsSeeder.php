<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

/**
 * Standard baseline chart, not just what Invoice/Receipt Entry need this
 * sprint — Purchase, Accounts Payable, Inventory, Expense, and Equity
 * modules all have a real account to post against the day they're built,
 * with no seeder edit required then.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // Assets
            ['code' => '1100', 'name' => 'Cash and Bank', 'account_type' => AccountType::ASSET],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'account_type' => AccountType::ASSET],
            ['code' => '1300', 'name' => 'Inventory', 'account_type' => AccountType::ASSET],
            // Liabilities
            ['code' => '1150', 'name' => 'Unapplied Customer Payments', 'account_type' => AccountType::LIABILITY],
            ['code' => '2000', 'name' => 'Accounts Payable', 'account_type' => AccountType::LIABILITY],
            ['code' => '2100', 'name' => 'Tax Payable', 'account_type' => AccountType::LIABILITY],
            ['code' => '2200', 'name' => 'Accrued Expenses', 'account_type' => AccountType::LIABILITY],
            // Equity
            ['code' => '3000', 'name' => "Owner's Equity", 'account_type' => AccountType::EQUITY],
            ['code' => '3100', 'name' => 'Retained Earnings', 'account_type' => AccountType::EQUITY],
            // Revenue
            ['code' => '4000', 'name' => 'Sales Revenue', 'account_type' => AccountType::REVENUE],
            ['code' => '4050', 'name' => 'Sales Returns and Allowances', 'account_type' => AccountType::REVENUE],
            ['code' => '4100', 'name' => 'Other Income', 'account_type' => AccountType::REVENUE],
            // Expense
            ['code' => '4900', 'name' => 'Discount Given', 'account_type' => AccountType::EXPENSE],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'account_type' => AccountType::EXPENSE],
            ['code' => '5100', 'name' => 'Purchase Expense', 'account_type' => AccountType::EXPENSE],
            ['code' => '6000', 'name' => 'Operating Expenses', 'account_type' => AccountType::EXPENSE],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $account['code']],
                ['name' => $account['name'], 'account_type' => $account['account_type'], 'is_active' => true],
            );
        }
    }
}
