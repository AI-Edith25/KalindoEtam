export type BankStatementFormatTemplate = 'bca' | 'mandiri'
export type BankStatementStatus = 'uploaded' | 'processed' | 'error'
export type BankReconciliationStatus = 'balanced' | 'unbalanced' | 'not_uploaded'

export interface BankStatement {
  id: string
  format_template: BankStatementFormatTemplate
  bank_account_id: string | null
  bank_account_name: string | null
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

/**
 * Detail tab: one row per Official Receipt (masuk)/Payment Voucher (keluar) for the day, read
 * straight from those documents' own fields -- bank_account is a display field only (for
 * cross-checking against whichever mutasi file), not a reconciliation dimension.
 */
export interface BankReconciliationComparisonRow {
  document_number: string | null
  date: string
  reference_number: string | null
  bank_account: string | null
  bank_account_id: string | null
  tipe: 'masuk' | 'keluar'
  debit: number
  kredit: number
}

export interface BankReconciliationAccountOption {
  id: string
  name: string
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
  bank_accounts: BankReconciliationAccountOption[]
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

export type BankReconciliationMatchStatus = 'cocok' | 'tidak_cocok'

export interface BankReconciliationMatchingJlSide {
  transaction: string | null
  date: string
  reference: string | null
  bank_account: string | null
  debit: number
  kredit: number
}

export interface BankReconciliationMatchingMutasiSide {
  date: string
  keterangan: string | null
  bank_account: string | null
  debit: number
  kredit: number
}

/** "Tabel Perbandingan": one row per matched/unmatched pair, 1:1 nominal matching within one account+date. */
export interface BankReconciliationMatchingRow {
  jl: BankReconciliationMatchingJlSide | null
  mutasi: BankReconciliationMatchingMutasiSide | null
  status: BankReconciliationMatchStatus
  selisih: number
}

export interface BankReconciliationMatchingResult {
  rows: BankReconciliationMatchingRow[]
  totals: {
    matched_count: number
    unmatched_jl_count: number
    unmatched_mutasi_count: number
    unmatched_debit_total: number
    unmatched_credit_total: number
  }
}
