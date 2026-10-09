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
            ['code' => '1100', 'name' => 'Cash and Bank', 'account_type' => AccountType::ASSET, 'is_cash_bank' => true],
            ['code' => '1250', 'name' => 'Advance to Suppliers', 'account_type' => AccountType::ASSET],
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
            ['code' => '5050', 'name' => 'Purchase Returns and Allowances', 'account_type' => AccountType::EXPENSE],
            ['code' => '5100', 'name' => 'Purchase Expense', 'account_type' => AccountType::EXPENSE],
            ['code' => '6000', 'name' => 'Operating Expenses', 'account_type' => AccountType::EXPENSE],
            // General Expense (Outgoing Payment, non-PO office spending) — granular categories under 6000's catch-all.
            ['code' => '6100', 'name' => 'Beban Transport', 'account_type' => AccountType::EXPENSE],
            ['code' => '6200', 'name' => 'Beban Konsumsi', 'account_type' => AccountType::EXPENSE],
            ['code' => '6300', 'name' => 'Beban ATK', 'account_type' => AccountType::EXPENSE],
            ['code' => '6400', 'name' => 'Beban Listrik & Air', 'account_type' => AccountType::EXPENSE],
            ['code' => '6900', 'name' => 'Beban Lain-lain', 'account_type' => AccountType::EXPENSE],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $account['code']],
                ['name' => $account['name'], 'account_type' => $account['account_type'], 'is_active' => true, 'is_cash_bank' => $account['is_cash_bank'] ?? false],
            );
        }

        $this->seedPiutangHierarchy();
    }

    /**
     * `112 PIUTANG` — a parent account with no postings of its own — over the four receivable
     * accounts, matching the hierarchy production got via the
     * 2026_10_09_000002_restructure_piutang_accounts_into_hierarchy migration. Seeded directly
     * in this shape (not as a flat '1200' later renamed) so a fresh install and production never
     * drift apart. `112.01` is the one real code here: hardcoded in ~10 files
     * (Invoice/CreditNote/DebitNote/PaymentAllocation/AccountingService and the three report
     * mapping seeders) as the Accounts Receivable posting target.
     */
    protected function seedPiutangHierarchy(): void
    {
        $parent = ChartOfAccount::query()->firstOrCreate(
            ['code' => '112'],
            ['name' => 'PIUTANG', 'account_type' => AccountType::ASSET, 'is_active' => true],
        );

        $children = [
            ['code' => '112.01', 'name' => 'Piutang Usaha'],
            ['code' => '112.02', 'name' => 'Piutang Karyawan'],
            ['code' => '112.03', 'name' => 'Piutang Lain-lain'],
            ['code' => '112.04', 'name' => 'Cadangan Piutang'],
        ];

        foreach ($children as $child) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $child['code']],
                ['name' => $child['name'], 'account_type' => AccountType::ASSET, 'is_active' => true, 'parent_id' => $parent->id],
            );
        }
    }
}
