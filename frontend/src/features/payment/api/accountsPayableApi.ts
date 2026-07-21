import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse } from '@/shared/types/api'
import type { AccountsPayable } from '../types'

export interface AccountsPayableListParams {
  supplier_id?: string
  per_page?: number
}

/** Read-only — Accounts Payable rows are only ever created as a side effect of Goods Receipt submission. Used here only as the Outgoing Payment picker's data source. */
export async function fetchAccountsPayables(params: AccountsPayableListParams): Promise<ApiListResponse<AccountsPayable>> {
  const { data } = await apiClient.get<ApiListResponse<AccountsPayable>>('/accounts-payables', { params })
  return data
}
