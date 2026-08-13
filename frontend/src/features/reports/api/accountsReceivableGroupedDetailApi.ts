import { apiClient } from '@/shared/services/apiClient'
import type { AccountsReceivableListParams } from '@/features/payment/api/accountsReceivableApi'
import type { ApiResponse } from '@/shared/types/api'
import type { ArDetailGroupedDetail } from '../types'

/** C3 (UAT review 2026-08-12) — "Perincian Piutang", same filters as fetchAccountsReceivables(), grouped Sales Person -> Customer. */
export async function fetchAccountsReceivableGroupedDetail(
  params: Omit<AccountsReceivableListParams, 'page' | 'per_page'>,
): Promise<ArDetailGroupedDetail> {
  const { data } = await apiClient.get<ApiResponse<ArDetailGroupedDetail>>('/accounts-receivables/detail-grouped', { params })
  return data.data
}
