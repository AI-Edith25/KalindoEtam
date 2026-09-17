import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { CashBookRow, CashBookView, GeneralLedgerImportBatch } from '../types'

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

/**
 * Cash Book import — re-imports this system's own Journal List > Cash Book export (not the
 * legacy Payment Voucher/Official Receipt Listing files those two importers handle). `view` tells
 * the backend which section label to expect (Cash Book Transaction/-Receipt/-Payment); a mismatch
 * throws a 409 (see isJournalTypeMismatch) unless confirmJournalType is set, which the caller does
 * on a second call once the user confirms past that warning. Poll the result with
 * fetchCashBookImportBatch until status is completed/failed.
 */
export async function importCashBook(file: File, view: CashBookView, confirmJournalType = false): Promise<GeneralLedgerImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('view', view)
  if (confirmJournalType) formData.append('confirm_journal_type', '1')

  const { data } = await apiClient.post<ApiResponse<GeneralLedgerImportBatch>>('/cash-book/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function fetchCashBookImportBatch(batchId: string): Promise<GeneralLedgerImportBatch> {
  const { data } = await apiClient.get<ApiResponse<GeneralLedgerImportBatch>>(`/cash-book/import/${batchId}`)
  return data.data
}
