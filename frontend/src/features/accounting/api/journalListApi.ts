import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { JournalListData } from '../types'

export interface JournalListParams {
  branch_id?: string
  date_from?: string
  date_to?: string
}

/** Never paginated — cash/bank lines for the filtered range, grouped server-side. */
export async function fetchJournalList(params: JournalListParams): Promise<JournalListData> {
  const { data } = await apiClient.get<ApiResponse<JournalListData>>('/journal-list', { params })
  return data.data
}
