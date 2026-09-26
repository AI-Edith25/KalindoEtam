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

export interface BankStatementLine {
  id: string
  transaction_date: string
  description: string
  debit_amount: number
  credit_amount: number
  running_balance: number | null
  match_status: BankStatementLineMatchStatus
  matched_document: { type: string; id: string; document_number: string } | null
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
