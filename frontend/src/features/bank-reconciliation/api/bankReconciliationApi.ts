import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  BankReconciliationComparison,
  BankReconciliationFile,
  BankReconciliationSummary,
  BankStatement,
  BankStatementFormatTemplate,
  BankStatementPreviewRow,
} from '../types'

export interface UploadBankStatementResult {
  batch: BankStatement
  preview_rows: BankStatementPreviewRow[]
}

/** Parses synchronously and returns a preview -- nothing is persisted until confirmBankStatement(). */
export async function uploadBankStatement(file: File, formatTemplate?: BankStatementFormatTemplate): Promise<UploadBankStatementResult> {
  const formData = new FormData()
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
  date_from: string
  date_to: string
}

/** One combined row per day -- the page's main table. */
export async function fetchDailyBalancingSummary(params: DailyBalancingParams): Promise<BankReconciliationSummary[]> {
  const { data } = await apiClient.get<ApiResponse<BankReconciliationSummary[]>>('/bank-reconciliation', { params })
  return data.data
}

export interface RecomputeReconciliationPayload {
  date_from: string
  date_to: string
}

export async function recomputeReconciliation(payload: RecomputeReconciliationPayload): Promise<void> {
  await apiClient.post('/bank-reconciliation/recompute', payload)
}

/** "Delete" (⋮ menu) -- removes the uploaded mutasi file(s) for this date; Cash Book (OR/PV) is untouched. */
export async function deleteBankReconciliationForDate(date: string): Promise<void> {
  await apiClient.delete('/bank-reconciliation', { params: { date } })
}

export interface BankReconciliationDayDetail {
  files: BankReconciliationFile[]
}

/** "See the file" -- the day's uploaded file(s). */
export async function fetchBankReconciliationDayDetail(date: string): Promise<BankReconciliationDayDetail> {
  const { data } = await apiClient.get<ApiResponse<BankReconciliationDayDetail>>('/bank-reconciliation/day-detail', {
    params: { date },
  })
  return data.data
}

/** Detail tab: Cash Book (Official Receipt/Payment Voucher journal entries) vs uploaded statement for one day, compared at the aggregate level. */
export async function fetchBankReconciliationComparisonRows(date: string): Promise<BankReconciliationComparison> {
  const { data } = await apiClient.get<ApiResponse<BankReconciliationComparison>>('/bank-reconciliation/comparison', {
    params: { date },
  })
  return data.data
}

/** Authenticated blob, same pattern as receiptEntryAttachmentApi.ts -- bank-statements stay behind auth:sanctum, no public URL. */
export async function fetchBankStatementFileObjectUrl(bankStatementId: string): Promise<string> {
  const { data } = await apiClient.get(`/bank-statements/${bankStatementId}/download`, { responseType: 'blob' })
  return URL.createObjectURL(data as Blob)
}
