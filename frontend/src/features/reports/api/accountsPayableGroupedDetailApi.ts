import { apiClient } from '@/shared/services/apiClient'
import type { AccountsPayableListParams } from '@/features/payment/api/accountsPayableApi'
import type { ApiResponse } from '@/shared/types/api'
import type { ApDetailGroupedDetail } from '../types'

/** "Perincian Hutang" — same filters as fetchAccountsPayables(), grouped by Supplier with due-date-anchored aging buckets. Mirrors fetchAccountsReceivableGroupedDetail(). */
export async function fetchAccountsPayableGroupedDetail(
  params: Omit<AccountsPayableListParams, 'page' | 'per_page'>,
): Promise<ApDetailGroupedDetail> {
  const { data } = await apiClient.get<ApiResponse<ApDetailGroupedDetail>>('/accounts-payables/detail-grouped', { params })
  return data.data
}
