import { apiClient } from '@/shared/services/apiClient'
import type { AccountsPayableListParams } from '@/features/payment/api/accountsPayableApi'
import type { AccountsPayable } from '@/features/payment/types'
import type { ApiListResponse } from '@/shared/types/api'

type Filters = Omit<AccountsPayableListParams, 'page' | 'per_page'>

/** Unpaginated, same filter shape as fetchAccountsPayables() — used by AP Detail's export. Mirrors fetchAccountsReceivablesAll(). */
export async function fetchAccountsPayablesAll(params: Filters): Promise<AccountsPayable[]> {
  const { data } = await apiClient.get<ApiListResponse<AccountsPayable>>('/accounts-payables/list-all', { params })
  return data.data
}
