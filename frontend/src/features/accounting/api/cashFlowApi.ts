import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { CashFlowData } from '../types'

export interface CashFlowParams {
  date_from: string
  date_to?: string
  branch_id?: string
  company_id?: string
}

/** Never paginated — a handful of Balance Sheet accounts at most. See docs/CASH_FLOW_DESIGN.md §10. */
export async function fetchCashFlow(params: CashFlowParams): Promise<CashFlowData> {
  const { data } = await apiClient.get<ApiResponse<CashFlowData>>('/cash-flow', { params })
  return data.data
}
