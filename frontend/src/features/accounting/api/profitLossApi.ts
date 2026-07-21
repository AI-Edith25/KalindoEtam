import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { ProfitLossData } from '../types'

export interface ProfitLossParams {
  date_from: string
  date_to?: string
  branch_id?: string
  company_id?: string
}

/** Never paginated — a handful of Revenue/Expense accounts at most. See docs/PROFIT_LOSS_DESIGN.md §9. */
export async function fetchProfitLoss(params: ProfitLossParams): Promise<ProfitLossData> {
  const { data } = await apiClient.get<ApiResponse<ProfitLossData>>('/profit-loss', { params })
  return data.data
}
