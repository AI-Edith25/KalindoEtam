import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { GeneralLedgerImportBatch } from '../types'

export type TrialBalanceDuplicatePolicy = 'skip' | 'create_anyway'

/**
 * Trial Balance import — Trial Balance itself is read-only (a presentation layer over
 * GeneralLedgerService::listAccounts()), so this posts one combined, balanced Journal Entry
 * instead (see TrialBalanceImportService). Two independent confirmations can come back as a 409
 * (see getImportConfirmationReason): a duplicate period already imported (resubmit with
 * duplicatePolicy), or the file's own declared "OUT OF BALANCE BY" figure (resubmit with
 * confirmOutOfBalance). Poll the result with fetchTrialBalanceImportBatch until completed/failed.
 */
export async function importTrialBalance(
  file: File,
  duplicatePolicy?: TrialBalanceDuplicatePolicy,
  confirmOutOfBalance = false,
): Promise<GeneralLedgerImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  if (duplicatePolicy) formData.append('duplicate_policy', duplicatePolicy)
  if (confirmOutOfBalance) formData.append('confirm_out_of_balance', '1')

  const { data } = await apiClient.post<ApiResponse<GeneralLedgerImportBatch>>('/trial-balance/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function fetchTrialBalanceImportBatch(batchId: string): Promise<GeneralLedgerImportBatch> {
  const { data } = await apiClient.get<ApiResponse<GeneralLedgerImportBatch>>(`/trial-balance/import/${batchId}`)
  return data.data
}
