export type BankStatementFormatTemplate = 'bca' | 'mandiri'
export type BankStatementStatus = 'uploaded' | 'processed' | 'error'
export type BankStatementLineMatchStatus = 'unmatched' | 'matched' | 'manual_matched'
export type BankReconciliationStatus = 'balanced' | 'unbalanced' | 'not_uploaded'

export interface BankStatement {
  id: string
  bank_account_id: string
  bank_account_name: string | null
  format_template: BankStatementFormatTemplate
  period_start: string | null
  period_end: string | null
  original_filename: string
  status: BankStatementStatus
  error_message: string | null
  created_at: string
}

/** Not yet persisted -- returned by the upload endpoint for the user to review before confirm(). */
export interface BankStatementPreviewRow {
  transaction_date: string
  description: string
  debit_amount: number
  credit_amount: number
  running_balance: number | null
}

export type BankReconciliationDetailView = 'import' | 'system'

/**
 * One row of the drill-down, either side: "import" (an uploaded statement line, system_amount =
 * its matched document's amount) or "system" (a Payment Voucher/Official Receipt, statement_amount
 * = the line it's matched to) -- same shape either way, browsing from the other side.
 */
export interface BankReconciliationDetailRow {
  id: string
  date: string
  customer: string | null
  system_amount: number | null
  statement_amount: number | null
  selisih: number
  status: BankStatementLineMatchStatus
  description?: string
  document_number?: string
  bank_statement_line_id?: string
  direction?: 'debit' | 'credit'
}

export interface BankReconciliationSummary {
  id: string
  bank_account_id: string
  bank_account_name: string | null
  date: string
  system_debit_total: number
  system_credit_total: number
  statement_debit_total: number
  statement_credit_total: number
  variance_debit: number
  variance_credit: number
  status: BankReconciliationStatus
  generated_at: string
}
