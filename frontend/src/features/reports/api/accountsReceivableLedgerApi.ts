import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { ArLedger, ArStatement } from '../types'

export interface AccountsReceivableLedgerParams {
  customer_id: string
  invoice_date_from?: string
  invoice_date_to?: string
}

export interface AccountsReceivableLedgerResponse extends ApiResponse<ArLedger> {
  meta: PaginationMeta
}

/** Kartu Piutang's on-screen table — one customer, paginated. */
export async function fetchAccountsReceivableLedger(
  params: AccountsReceivableLedgerParams & { page?: number; per_page?: number },
): Promise<AccountsReceivableLedgerResponse> {
  const { data } = await apiClient.get<AccountsReceivableLedgerResponse>('/accounts-receivables/ledger', { params })
  return data
}

/** Kartu Piutang's print preview — same core as fetchAccountsReceivableLedger(), unpaginated so the statement covers the whole filtered period. */
export async function fetchAccountsReceivableLedgerFull(params: AccountsReceivableLedgerParams): Promise<ArLedger> {
  const { data } = await apiClient.get<ApiResponse<ArLedger>>('/accounts-receivables/ledger/print', { params })
  return data.data
}

/** Kartu Piutang's statement print ("STATEMENT OF ACCOUNT") — separate debit-row-grouped shape from fetchAccountsReceivableLedgerFull(), which the export still uses untouched. */
export async function fetchAccountsReceivableStatement(params: AccountsReceivableLedgerParams): Promise<ArStatement> {
  const { data } = await apiClient.get<ApiResponse<ArStatement>>('/accounts-receivables/ledger/statement-print', { params })
  return data.data
}

/**
 * Kartu Piutang customer list's "Sisa"-vs-detail-ledger reconciliation figure — see
 * AccountsReceivableRepository::unappliedPaymentTotalsByCustomerIds(). No params: the
 * receipt_entries table is small enough to return every customer's figure in one call — passing
 * one id per customer in the query string is what caused a 414 Request-URI-Too-Long on a large
 * customer list. Map omits any customer with no unapplied payment.
 */
export async function fetchUnappliedPaymentTotals(): Promise<Record<string, number>> {
  const { data } = await apiClient.get<ApiResponse<Record<string, number>>>('/accounts-receivables/unapplied-payment-totals')
  return data.data
}

/** Kartu Piutang's Export CSV/XLSX — blob response, same auth-header rule as exportAccountsReceivables(). */
export async function exportAccountsReceivableLedger(params: AccountsReceivableLedgerParams, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/accounts-receivables/ledger/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
