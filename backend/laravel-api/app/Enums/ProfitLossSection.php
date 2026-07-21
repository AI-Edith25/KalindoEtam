<?php

namespace App\Enums;

/**
 * The five buckets this codebase's Profit & Loss report groups accounts
 * into — internal to ProfitLossService, not a constraint on the generic
 * report_account_mappings.section column (a plain string, scoped per
 * ReportStatementType, per docs/PROFIT_LOSS_DESIGN.md §4). Declaration
 * order here is also the report's fixed render order (§7).
 */
enum ProfitLossSection: string
{
    case REVENUE = 'revenue';
    case COST_OF_GOODS_SOLD = 'cost_of_goods_sold';
    case OPERATING_EXPENSE = 'operating_expense';
    case OTHER_INCOME = 'other_income';
    case OTHER_EXPENSE = 'other_expense';

    public function label(): string
    {
        return match ($this) {
            self::REVENUE => 'Revenue',
            self::COST_OF_GOODS_SOLD => 'Cost of Goods Sold',
            self::OPERATING_EXPENSE => 'Operating Expenses',
            self::OTHER_INCOME => 'Other Income',
            self::OTHER_EXPENSE => 'Other Expenses',
        };
    }

    /**
     * Which side of the ledger *this section* increases on — independent of
     * any one mapped account's own account_type. Needed because a contra
     * account can be mapped into a section whose convention is the opposite
     * of the account's own type (Discount Given is `expense`/debit-normal
     * but mapped into the credit-normal Revenue section as a deduction, per
     * docs/PROFIT_LOSS_DESIGN.md §4) — without this, such an account would
     * add to the section instead of netting against it.
     */
    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::REVENUE, self::OTHER_INCOME => false,
            self::COST_OF_GOODS_SOLD, self::OPERATING_EXPENSE, self::OTHER_EXPENSE => true,
        };
    }
}
