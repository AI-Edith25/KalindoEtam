import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { SalesListingRow } from '@/features/reports/types'
import type { SalesJournalView } from '../types'

export interface SalesJournalParams {
  view: SalesJournalView
  page?: number
  per_page?: number
  search?: string
  branch_id?: string
  date_from?: string
  date_to?: string
}

export interface SalesJournalListResponse extends ApiListResponse<SalesListingRow> {
  meta: PaginationMeta
}

/** Screen — document-level, reuses SalesListingRow's shape (SalesListingRowResource, unchanged). */
export async function fetchSalesJournal(params: SalesJournalParams): Promise<SalesJournalListResponse> {
  const { data } = await apiClient.get<SalesJournalListResponse>('/sales-journal', { params })
  return data
}

export interface SalesJournalExportParams {
  view: SalesJournalView
  format: 'xlsx' | 'csv'
  search?: string
  branch_id?: string
  date_from?: string
  date_to?: string
}

/** Export — item-level, matching the legacy JournalList_Sales/SalesReturn.xlsx template. Blob response (Bearer auth, not a cookie). */
export async function exportSalesJournal(params: SalesJournalExportParams): Promise<Blob> {
  const { data } = await apiClient.get('/sales-journal/export', { params, responseType: 'blob' })
  return data as Blob
}

const FILE_NAME_SEGMENTS: Record<SalesJournalView, string> = {
  invoice: 'SalesInvoice',
  credit_note: 'SalesReturn',
}

/** "JournalList-{SalesInvoice|SalesReturn}-ddmmyyyy.{xlsx|csv}" — mirrors SalesJournalController::export()'s own filename exactly, same pattern as journalListFileName(). */
export function salesJournalFileName(view: SalesJournalView, format: 'xlsx' | 'csv'): string {
  const now = new Date()
  const dd = String(now.getDate()).padStart(2, '0')
  const mm = String(now.getMonth() + 1).padStart(2, '0')
  const yyyy = now.getFullYear()

  return `JournalList-${FILE_NAME_SEGMENTS[view]}-${dd}${mm}${yyyy}.${format}`
}
