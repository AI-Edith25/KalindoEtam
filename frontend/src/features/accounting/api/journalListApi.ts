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

/** E1 (UAT review 2026-08-12) — XLSX export, same filters/grouping as fetchJournalList(). Blob response (Bearer auth, not a cookie). */
export async function exportJournalListXlsx(params: JournalListParams): Promise<Blob> {
  const { data } = await apiClient.get('/journal-list/export', { params, responseType: 'blob' })
  return data as Blob
}
