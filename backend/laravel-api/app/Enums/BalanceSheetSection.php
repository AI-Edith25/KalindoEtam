<?php

namespace App\Enums;

/**
 * The six buckets this codebase's Balance Sheet groups accounts into —
 * internal to BalanceSheetService, not a constraint on the generic
 * report_account_mappings.section column (a plain string, scoped per
 * ReportStatementType, per docs/PROFIT_LOSS_DESIGN.md §4). Declaration
 * order here is also the report's fixed render order. See
 * docs/BALANCE_SHEET_DESIGN.md §4. Mirrors ProfitLossSection's shape —
 * Current Year Profit is deliberately not a case here: it is never a
 * mapped account, only a derived figure (see BalanceSheetService).
 */
enum BalanceSheetSection: string
{
    case CURRENT_ASSET = 'current_asset';
    case NON_CURRENT_ASSET = 'non_current_asset';
    case CURRENT_LIABILITY = 'current_liability';
    case LONG_TERM_LIABILITY = 'long_term_liability';
    case SHARE_CAPITAL = 'share_capital';
    case RETAINED_EARNINGS = 'retained_earnings';

    public function label(): string
    {
        return match ($this) {
            self::CURRENT_ASSET => 'Current Assets',
            self::NON_CURRENT_ASSET => 'Non-Current Assets',
            self::CURRENT_LIABILITY => 'Current Liabilities',
            self::LONG_TERM_LIABILITY => 'Long-Term Liabilities',
            self::SHARE_CAPITAL => 'Share Capital',
            self::RETAINED_EARNINGS => 'Retained Earnings',
        };
    }

    /**
     * Which side of the ledger *this section* increases on — independent of
     * any one mapped account's own account_type, the same contra-account
     * protection ProfitLossSection::isDebitNormal() already provides (no
     * contra-asset account is seeded today, but the mechanism is reused,
     * not reinvented, for the day one is added).
     */
    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::CURRENT_ASSET, self::NON_CURRENT_ASSET => true,
            self::CURRENT_LIABILITY, self::LONG_TERM_LIABILITY,
            self::SHARE_CAPITAL, self::RETAINED_EARNINGS => false,
        };
    }
}
