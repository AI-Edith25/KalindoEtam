import type { ApprovalFlow } from '../approval/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

export interface JournalEntryLine {
  id: string
  chart_of_account_id: string
  chart_of_account: { id: string; code: string; name: string } | null
  branch_id: string | null
  branch: { id: string; name: string } | null
  debit: string | number
  credit: string | number
  description: string | null
}

export interface JournalEntry {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  posting_date: string
  // Short stable key (e.g. "invoice"), never a raw class name — see reference_label for display.
  reference_type: string | null
  reference_label: string | null
  reference_id: string | null
  reference_document_number: string | null
  description: string | null
  total_debit: string | number
  total_credit: string | number
  reverses_id: string | null
  reverses_document_number: string | null
  reversed_by_id: string | null
  reversed_by_document_number: string | null
  lines: JournalEntryLine[]
  created_by_name: string | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
  requires_approval: boolean
  latest_approval: ApprovalFlow | null
}

export interface JournalEntryFormValues {
  posting_date: string
  description: string | null
  lines: { chart_of_account_id: string; debit: number; credit: number; description: string | null }[]
}

export interface JournalEntryFilterValues {
  status: DocumentStatus | null
  referenceType: string | null
  accountId: string | null
  dateFrom: string
  dateTo: string
}

/**
 * General Ledger (Sprint 15B) — a read model derived entirely from
 * journal_entries/journal_entry_lines, never a new accounting table. See
 * docs/GENERAL_LEDGER_DESIGN.md. One row per Chart of Account for the
 * Ledger List.
 */
export interface LedgerAccountSummary {
  id: string
  code: string
  name: string
  account_type: string
  is_active: boolean
  opening_balance: string | number
  debit: string | number
  credit: string | number
  ending_balance: string | number
}

/** One Ledger Detail row — a journal_entry_line with its running balance attached. */
export interface LedgerLine {
  id: string
  posting_date: string
  journal_entry_id: string
  journal_number: string | null
  reference_type: string | null
  reference_label: string | null
  reference_id: string | null
  reference_document_number: string | null
  description: string | null
  debit: string | number
  credit: string | number
  running_balance: string | number
}

export interface AccountLedgerData {
  account: { id: string; code: string; name: string; account_type: string; is_active: boolean }
  opening_balance: string | number
  ending_balance: string | number
  lines: LedgerLine[]
}

/** Shared by both Ledger List and Ledger Detail — Detail additionally uses referenceNumber. See docs/GENERAL_LEDGER_DESIGN.md §4. */
export interface GeneralLedgerFilterValues {
  status: DocumentStatus | null
  referenceType: string | null
  referenceNumber: string
  branchId: string | null
  companyId: string | null
  dateFrom: string
  dateTo: string
}

/**
 * Trial Balance (Sprint 16A) — a presentation layer over
 * GeneralLedgerService::listAccounts(), never a second balance calculation.
 * See docs/TRIAL_BALANCE_DESIGN.md. One row per Chart of Account, ending
 * balance already placed into a Debit or Credit column.
 */
export interface TrialBalanceRow {
  id: string
  code: string
  name: string
  account_type: string
  debit: string | number
  credit: string | number
}

export interface TrialBalanceData {
  rows: TrialBalanceRow[]
  total_debit: string | number
  total_credit: string | number
  is_balanced: boolean
}

export type TrialBalancePeriodPreset = 'this_month' | 'this_quarter' | 'this_fiscal_year' | 'custom'
export type ProfitLossPeriodPreset = TrialBalancePeriodPreset

/** Backend-relevant filters mirror GeneralLedgerFilterValues (minus referenceNumber/referenceType/status —
 * not surfaced in the Trial Balance UI, see docs/TRIAL_BALANCE_DESIGN.md §5). periodPreset/codeFrom/codeTo/
 * hideZeroBalance are frontend-only — never sent to the API. */
export interface TrialBalanceFilterValues {
  periodPreset: TrialBalancePeriodPreset
  dateFrom: string
  dateTo: string
  codeFrom: string
  codeTo: string
  branchId: string | null
  companyId: string | null
  hideZeroBalance: boolean
}

/**
 * Profit & Loss (Sprint 17B) — a presentation layer over
 * GeneralLedgerService::listAccounts(), classified via the separate
 * report_account_mappings table (chart_of_accounts gains no new columns).
 * See docs/PROFIT_LOSS_DESIGN.md. Unlike Trial Balance, uses period
 * movement only — `amount` is already section-sign-adjusted (a contra
 * account like Discount Given nets negative within the Revenue section).
 */
export interface ProfitLossLine {
  id: string
  code: string
  name: string
  amount: string | number
}

export interface ProfitLossSectionData {
  key: string
  label: string
  lines: ProfitLossLine[]
  subtotal: string | number
}

export interface ProfitLossData {
  sections: ProfitLossSectionData[]
  gross_profit: string | number
  operating_income: string | number
  net_profit_before_tax: string | number
  tax: string | number | null
  net_profit: string | number
}

/** date_from is mandatory for Profit & Loss (§6) — the UI never offers a way to clear the Reporting Period. */
export interface ProfitLossFilterValues {
  periodPreset: ProfitLossPeriodPreset
  dateFrom: string
  dateTo: string
  branchId: string | null
  companyId: string | null
}

/**
 * Balance Sheet (Sprint 18B) — a presentation layer over
 * GeneralLedgerService::listAccounts() (cumulative ending_balance, not
 * period movement) and ProfitLossService::summarize() (Current Year
 * Profit / Retained Earnings), classified via the same report_account_mappings
 * table Profit & Loss uses. See docs/BALANCE_SHEET_DESIGN.md. `amount` is
 * already section-sign-adjusted, same convention as ProfitLossLine.
 */
export interface BalanceSheetLine {
  id: string
  code: string
  name: string
  amount: string | number
}

export interface BalanceSheetSectionData {
  key: string
  label: string
  lines: BalanceSheetLine[]
  subtotal: string | number
}

export interface BalanceSheetData {
  as_of_date: string
  sections: BalanceSheetSectionData[]
  total_assets: string | number
  total_liabilities: string | number
  share_capital: string | number
  retained_earnings: string | number
  current_year_profit: string | number
  total_equity: string | number
  total_liabilities_and_equity: string | number
  is_balanced: boolean
}

/** as_of_date is mandatory — a Balance Sheet is a point-in-time snapshot, not a period. See docs/BALANCE_SHEET_DESIGN.md §7. */
export interface BalanceSheetFilterValues {
  asOfDate: string
  branchId: string | null
  companyId: string | null
}

/**
 * Cash Flow (Sprint 19B) — Indirect Method, a presentation layer over
 * ProfitLossService::summarize() (Net Profit for the Period) and
 * GeneralLedgerService::listAccounts() (opening/ending balance per account
 * in one call), classified via the same report_account_mappings table
 * Profit & Loss/Balance Sheet use. See docs/CASH_FLOW_DESIGN.md. `amount`
 * is already sign-adjusted: an asset's increase displays negative (a cash
 * use), a liability/equity's increase displays positive (a cash source).
 */
export interface CashFlowLine {
  id: string
  code: string
  name: string
  amount: string | number
}

export interface CashFlowActivitySection {
  key: string
  label: string
  lines: CashFlowLine[]
  net_cash: string | number
}

export interface CashFlowData {
  net_profit: string | number
  operating: CashFlowActivitySection
  investing: CashFlowActivitySection
  financing: CashFlowActivitySection
  net_cash_movement: string | number
  opening_cash: string | number
  closing_cash: string | number
  is_balanced: boolean
}

export type CashFlowPeriodPreset = ProfitLossPeriodPreset

/** date_from is mandatory — a Cash Flow Statement explains movement during a period, the same reasoning as Profit & Loss. See docs/CASH_FLOW_DESIGN.md §7. */
export interface CashFlowFilterValues {
  periodPreset: CashFlowPeriodPreset
  dateFrom: string
  dateTo: string
  branchId: string | null
  companyId: string | null
}

/**
 * Period Closing (Sprint 20B) — a locking mechanism only, never a second
 * accounting calculation. See docs/PERIOD_CLOSING_DESIGN.md. Exactly two
 * statuses; closed_by/closed_at/reopened_by/reopened_at hold only the
 * latest close/reopen event (the full history lives server-side in
 * DocumentTimeline).
 */
export type PeriodStatus = 'open' | 'closed'

export interface FiscalYear {
  id: string
  company_id: string
  name: string
  start_date: string
  end_date: string
  accounting_periods?: AccountingPeriod[]
}

export interface AccountingPeriod {
  id: string
  fiscal_year_id: string
  fiscal_year_name: string | null
  name: string
  start_date: string
  end_date: string
  status: PeriodStatus
  closed_by: string | null
  closed_at: string | null
  reopened_by: string | null
  reopened_at: string | null
}

/** One row of the closing validation checklist — shown before a Close is confirmed. */
export interface PeriodValidationCheck {
  key: string
  label: string
  passed: boolean
  detail: string | null
}
