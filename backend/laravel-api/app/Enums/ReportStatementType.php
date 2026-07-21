<?php

namespace App\Enums;

/**
 * Discriminates which report a ReportAccountMapping row classifies an
 * account for. New reports (an IFRS variant, a Tax report) add a new case
 * here plus their own mapping rows — never a new chart_of_accounts column.
 * See docs/PROFIT_LOSS_DESIGN.md §4, docs/BALANCE_SHEET_DESIGN.md §4, and
 * docs/CASH_FLOW_DESIGN.md §5.
 */
enum ReportStatementType: string
{
    case PROFIT_LOSS = 'profit_loss';
    case BALANCE_SHEET = 'balance_sheet';
    case CASH_FLOW = 'cash_flow';
}
