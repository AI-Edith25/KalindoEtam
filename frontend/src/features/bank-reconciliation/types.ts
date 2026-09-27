export type BankStatementFormatTemplate = 'bca' | 'mandiri'
export type BankStatementStatus = 'uploaded' | 'processed' | 'error'
export type BankReconciliationStatus = 'balanced' | 'unbalanced' | 'not_uploaded'

export interface BankStatement {
  id: string
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

/** Point 2's "View" -- one uploaded file covering a summary row's day. */
export interface BankReconciliationFile {
  id: string
  original_filename: string
  uploaded_at: string | null
  uploaded_by: string | null
}

/** Detail tab: one row per Cash Book (Official Receipt/Payment Voucher) journal entry for the day, reduced to its cash/bank leg. */
export interface BankReconciliationComparisonRow {
  document_number: string | null
  date: string
  keterangan: string | null
  tipe: 'masuk' | 'keluar'
  debit: number
  kredit: number
}

export interface BankReconciliationCategoryComparison {
  cash_book: number
  bank: number
  variance: number
  status: 'balanced' | 'unbalanced'
}

/** Detail tab's full response -- Cash Book rows + totals, compared against the bank statement at the aggregate level only, never row-by-row. */
export interface BankReconciliationComparison {
  rows: BankReconciliationComparisonRow[]
  totals: {
    debit: number
    kredit: number
    selisih: number
  }
  bank_mutasi: {
    saldo_awal: number | null
    total_masuk: number
    total_keluar: number
    saldo_akhir: number | null
  }
  comparison: {
    debit: BankReconciliationCategoryComparison
    kredit: BankReconciliationCategoryComparison
  }
}

export interface BankReconciliationSummary {
  id: string
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
