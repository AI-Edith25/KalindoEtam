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

/** Point 2's "View" -- one uploaded file covering a summary row's day. */
export interface BankReconciliationFile {
  id: string
  original_filename: string
  uploaded_at: string | null
  uploaded_by: string | null
}

/** Point 3's sub-table: one row per Cash Book document, plus one per statement line with no matching document. */
export type BankReconciliationComparisonStatus = 'match' | 'not_in_bank' | 'not_in_cash_book'

export interface BankReconciliationComparisonRow {
  id: string
  date: string
  cash_book_label: string | null
  cash_book_debit: number | null
  cash_book_credit: number | null
  statement_label: string | null
  statement_debit: number | null
  statement_credit: number | null
  selisih: number
  status: BankReconciliationComparisonStatus
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
