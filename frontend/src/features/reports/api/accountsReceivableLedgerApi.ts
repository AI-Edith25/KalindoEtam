import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { ArLedger } from '../types'

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

/** Kartu Piutang's Export CSV/XLSX — blob response, same auth-header rule as exportAccountsReceivables(). */
export async function exportAccountsReceivableLedger(params: AccountsReceivableLedgerParams, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/accounts-receivables/ledger/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
