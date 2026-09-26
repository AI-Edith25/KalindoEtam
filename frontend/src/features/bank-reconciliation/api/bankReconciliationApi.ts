import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  BankReconciliationSummary,
  BankStatement,
  BankStatementFormatTemplate,
  BankStatementLine,
  BankStatementPreviewRow,
} from '../types'

export interface UploadBankStatementResult {
  batch: BankStatement
  preview_rows: BankStatementPreviewRow[]
}

/** Parses synchronously and returns a preview -- nothing is persisted until confirmBankStatement(). */
export async function uploadBankStatement(
  bankAccountId: string,
  file: File,
  formatTemplate?: BankStatementFormatTemplate,
): Promise<UploadBankStatementResult> {
  const formData = new FormData()
  formData.append('bank_account_id', bankAccountId)
  formData.append('file', file)
  if (formatTemplate) formData.append('format_template', formatTemplate)

  const { data } = await apiClient.post<ApiResponse<UploadBankStatementResult>>('/bank-statements', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function confirmBankStatement(batchId: string): Promise<BankStatement> {
  const { data } = await apiClient.post<ApiResponse<BankStatement>>(`/bank-statements/${batchId}/confirm`)
  return data.data
}

export async function fetchBankStatement(batchId: string): Promise<BankStatement> {
  const { data } = await apiClient.get<ApiResponse<BankStatement>>(`/bank-statements/${batchId}`)
  return data.data
}

export interface DailyBalancingParams {
  bank_account_id?: string
  date_from: string
  date_to: string
}

export async function fetchDailyBalancingSummary(params: DailyBalancingParams): Promise<BankReconciliationSummary[]> {
  const { data } = await apiClient.get<ApiResponse<BankReconciliationSummary[]>>('/bank-reconciliation', { params })
  return data.data
}

export async function fetchBankStatementLines(bankAccountId: string, date: string): Promise<BankStatementLine[]> {
  const { data } = await apiClient.get<ApiResponse<BankStatementLine[]>>('/bank-reconciliation/lines', {
    params: { bank_account_id: bankAccountId, date },
  })
  return data.data
}

export interface RecomputeReconciliationPayload {
  bank_account_id: string
  date_from: string
  date_to: string
  tolerance_days?: number
}

export async function recomputeReconciliation(payload: RecomputeReconciliationPayload): Promise<void> {
  await apiClient.post('/bank-reconciliation/recompute', payload)
}

export async function manualMatchBankStatementLine(lineId: string, documentType: string, documentId: string): Promise<BankStatementLine> {
  const { data } = await apiClient.post<ApiResponse<BankStatementLine>>(`/bank-statement-lines/${lineId}/manual-match`, {
    document_type: documentType,
    document_id: documentId,
  })
  return data.data
}
