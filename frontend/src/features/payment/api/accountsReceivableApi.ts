import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse } from '@/shared/types/api'
import type { AccountsReceivable } from '../types'

export interface AccountsReceivableListParams {
  customer_id?: string
  per_page?: number
}

/** Read-only — Accounts Receivable rows are only ever created as a side effect of Delivery submission. Used here only as the Incoming Payment picker's data source. */
export async function fetchAccountsReceivables(params: AccountsReceivableListParams): Promise<ApiListResponse<AccountsReceivable>> {
  const { data } = await apiClient.get<ApiListResponse<AccountsReceivable>>('/accounts-receivables', { params })
  return data
}
