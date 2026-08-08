import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { AccountsReceivable } from '../types'

export interface AccountsReceivableListParams {
  customer_id?: string
  status?: string
  aging_bucket?: string
  date_from?: string
  date_to?: string
  invoice_date_from?: string
  invoice_date_to?: string
  page?: number
  per_page?: number
}

/** AR Detail Report's "Total Outstanding" footer needs this alongside the paginated list — kept local rather than widening the shared PaginationMeta every other list endpoint uses. */
export interface AccountsReceivableListResponse extends ApiListResponse<AccountsReceivable> {
  meta: PaginationMeta & { total_outstanding: number }
}

/** Read-only — Accounts Receivable rows are only ever created as a side effect of Delivery submission. Used by the Incoming Payment picker and the AR Detail report. */
export async function fetchAccountsReceivables(params: AccountsReceivableListParams): Promise<AccountsReceivableListResponse> {
  const { data } = await apiClient.get<AccountsReceivableListResponse>('/accounts-receivables', { params })
  return data
}
