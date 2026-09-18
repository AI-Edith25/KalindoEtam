import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { GeneralLedgerImportBatch } from '../types'

export type BalanceSheetDuplicatePolicy = 'skip' | 'create_anyway'

/**
 * Balance Sheet import — Balance Sheet itself is read-only (a presentation layer over
 * GeneralLedgerService's cumulative ending_balance plus ProfitLossService's Current Year Profit,
 * see BalanceSheetService), so this posts one combined, balanced Journal Entry instead (see
 * BalanceSheetImportService). A duplicate period already imported comes back as a 409 (see
 * getImportConfirmationReason); resubmit with duplicatePolicy to proceed. Poll the result with
 * fetchBalanceSheetImportBatch until completed/failed.
 */
export async function importBalanceSheet(file: File, duplicatePolicy?: BalanceSheetDuplicatePolicy): Promise<GeneralLedgerImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  if (duplicatePolicy) formData.append('duplicate_policy', duplicatePolicy)

  const { data } = await apiClient.post<ApiResponse<GeneralLedgerImportBatch>>('/balance-sheet/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function fetchBalanceSheetImportBatch(batchId: string): Promise<GeneralLedgerImportBatch> {
  const { data } = await apiClient.get<ApiResponse<GeneralLedgerImportBatch>>(`/balance-sheet/import/${batchId}`)
  return data.data
}
