import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { TrialBalanceData } from '../types'

export interface TrialBalanceParams {
  branch_id?: string
  company_id?: string
  date_from?: string
  date_to?: string
}

/** Never paginated — one row per Chart of Account, same as General Ledger List. See docs/TRIAL_BALANCE_DESIGN.md §7. */
export async function fetchTrialBalance(params: TrialBalanceParams): Promise<TrialBalanceData> {
  const { data } = await apiClient.get<ApiResponse<TrialBalanceData>>('/trial-balance', { params })
  return data.data
}
