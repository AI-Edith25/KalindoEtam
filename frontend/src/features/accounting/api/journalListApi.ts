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

const FILE_NAME_SEGMENTS: Record<CashBookView, string> = {
  all: 'Cashbook',
  receipt: 'OfficialReceipt',
  payment: 'PaymentVoucher',
}

/** "JournalList-{Cashbook|OfficialReceipt|PaymentVoucher}-ddmmyyyy.{xlsx|csv}" — mirrors JournalListController::export()'s own filename exactly (downloadBlob() forces this name client-side; a blob: URL carries no Content-Disposition to fall back on). */
export function journalListFileName(view: CashBookView, format: 'xlsx' | 'csv'): string {
  const now = new Date()
  const dd = String(now.getDate()).padStart(2, '0')
  const mm = String(now.getMonth() + 1).padStart(2, '0')
  const yyyy = now.getFullYear()

  return `JournalList-${FILE_NAME_SEGMENTS[view]}-${dd}${mm}${yyyy}.${format}`
}
