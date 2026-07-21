import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { BalanceSheetData } from '../types'

export interface BalanceSheetParams {
  as_of_date: string
  branch_id?: string
  company_id?: string
}

/** Never paginated — a handful of Asset/Liability/Equity accounts at most. See docs/BALANCE_SHEET_DESIGN.md §10. */
export async function fetchBalanceSheet(params: BalanceSheetParams): Promise<BalanceSheetData> {
  const { data } = await apiClient.get<ApiResponse<BalanceSheetData>>('/balance-sheet', { params })
  return data.data
}
