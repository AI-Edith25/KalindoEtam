import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse } from '@/shared/types/api'
import type { CashBookRow, CashBookView } from '../types'

export interface CashBookListParams {
  view: CashBookView
  page: number
  search?: string
  status?: string
  branch_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Screen data for Journal List's Cash Book Transaction tab — document-level, server-paginated. */
export async function fetchCashBook(params: CashBookListParams): Promise<ApiListResponse<CashBookRow>> {
  const { data } = await apiClient.get<ApiListResponse<CashBookRow>>('/cash-book', { params })
  return data
}
