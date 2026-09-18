import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { GeneralLedgerImportBatch } from '../types'

export type ProfitLossDuplicatePolicy = 'skip' | 'create_anyway'

/**
 * Income Statement import — Income Statement itself is read-only (a presentation layer over
 * GeneralLedgerService's period movement, see ProfitLossService), so this posts one combined,
 * balanced Journal Entry instead (see IncomeStatementImportService). Named to match the existing
 * profitLossApi.ts (the internal module/permission/URL segment were never renamed — only the
 * frontend page's own display label and route were), not the user-facing "Income Statement" name.
 * A duplicate period already imported comes back as a 409 (see getImportConfirmationReason);
 * resubmit with duplicatePolicy to proceed. Poll the result with fetchProfitLossImportBatch until
 * completed/failed.
 */
export async function importProfitLoss(file: File, duplicatePolicy?: ProfitLossDuplicatePolicy): Promise<GeneralLedgerImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  if (duplicatePolicy) formData.append('duplicate_policy', duplicatePolicy)

  const { data } = await apiClient.post<ApiResponse<GeneralLedgerImportBatch>>('/profit-loss/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function fetchProfitLossImportBatch(batchId: string): Promise<GeneralLedgerImportBatch> {
  const { data } = await apiClient.get<ApiResponse<GeneralLedgerImportBatch>>(`/profit-loss/import/${batchId}`)
  return data.data
}
