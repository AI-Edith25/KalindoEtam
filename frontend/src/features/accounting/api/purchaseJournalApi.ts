import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { PurchaseJournalRow, PurchaseJournalView } from '../types'

export interface PurchaseJournalParams {
  view: PurchaseJournalView
  page?: number
  per_page?: number
  search?: string
  date_from?: string
  date_to?: string
}

export interface PurchaseJournalListResponse extends ApiListResponse<PurchaseJournalRow> {
  meta: PaginationMeta
}

/** Screen — document-level, one row per Purchase Invoice/Purchase Return. */
export async function fetchPurchaseJournal(params: PurchaseJournalParams): Promise<PurchaseJournalListResponse> {
  const { data } = await apiClient.get<PurchaseJournalListResponse>('/purchase-journal', { params })
  return data
}

export interface PurchaseJournalExportParams {
  view: PurchaseJournalView
  format: 'xlsx' | 'csv'
  search?: string
  date_from?: string
  date_to?: string
}

/** Export — item-level, matching the legacy JournalList_Purchase/PurchaseReturn.xlsx template. Blob response (Bearer auth, not a cookie). */
export async function exportPurchaseJournal(params: PurchaseJournalExportParams): Promise<Blob> {
  const { data } = await apiClient.get('/purchase-journal/export', { params, responseType: 'blob' })
  return data as Blob
}

const FILE_NAME_SEGMENTS: Record<PurchaseJournalView, string> = {
  purchase_invoice: 'PurchaseInvoice',
  purchase_return: 'PurchaseReturn',
}

/** "JournalList-{PurchaseInvoice|PurchaseReturn}-ddmmyyyy.{xlsx|csv}" — mirrors PurchaseJournalController::export()'s own filename exactly. */
export function purchaseJournalFileName(view: PurchaseJournalView, format: 'xlsx' | 'csv'): string {
  const now = new Date()
  const dd = String(now.getDate()).padStart(2, '0')
  const mm = String(now.getMonth() + 1).padStart(2, '0')
  const yyyy = now.getFullYear()

  return `JournalList-${FILE_NAME_SEGMENTS[view]}-${dd}${mm}${yyyy}.${format}`
}
