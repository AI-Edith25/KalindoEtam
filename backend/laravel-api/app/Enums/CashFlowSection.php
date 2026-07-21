<?php

namespace App\Enums;

/**
 * The four buckets this codebase's Cash Flow Statement groups accounts
 * into — internal to CashFlowService, not a constraint on the generic
 * report_account_mappings.section column. Deliberately its own vocabulary,
 * not a reuse of BalanceSheetSection's values: Balance Sheet's
 * `current_asset` groups Cash and Bank together with Accounts
 * Receivable/Inventory, but Cash Flow must split Cash out as the thing
 * being explained (Opening/Closing Cash) from the accounts that adjust it
 * (Operating Activities). See docs/CASH_FLOW_DESIGN.md §5.
 */
enum CashFlowSection: string
{
    case CASH_AND_EQUIVALENTS = 'cash_and_equivalents';
    case OPERATING_ADJUSTMENT = 'operating_adjustment';
    case INVESTING_ACTIVITY = 'investing_activity';
    case FINANCING_ACTIVITY = 'financing_activity';

    public function label(): string
    {
        return match ($this) {
            self::CASH_AND_EQUIVALENTS => 'Cash and Cash Equivalents',
            self::OPERATING_ADJUSTMENT => 'Operating Activities',
            self::INVESTING_ACTIVITY => 'Investing Activities',
            self::FINANCING_ACTIVITY => 'Financing Activities',
        };
    }
}
