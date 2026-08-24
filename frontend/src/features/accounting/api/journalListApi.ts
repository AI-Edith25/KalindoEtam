import { apiClient } from '@/shared/services/apiClient'
import type { CashBookView } from '../types'

export interface JournalListExportParams {
  view: CashBookView
  format: 'xlsx' | 'csv'
  search?: string
  status?: string
  branch_id?: string
  date_from?: string
  date_to?: string
}

/** Journal List's export — journal-line level, matching the legacy xlsJournalList(*).xlsx files exactly. Blob response (Bearer auth, not a cookie). */
export async function exportJournalList(params: JournalListExportParams): Promise<Blob> {
  const { data } = await apiClient.get('/journal-list/export', { params, responseType: 'blob' })
  return data as Blob
}
