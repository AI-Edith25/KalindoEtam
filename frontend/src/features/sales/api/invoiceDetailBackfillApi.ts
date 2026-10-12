import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { SalesInvoiceDetailBackfillImportBatch, SalesInvoiceDetailBackfillResult } from '../types'

/** Preflight only — parses and validates, returns a batch awaiting confirmation. Nothing is committed yet. */
export async function storeSalesInvoiceDetailBackfill(file: File): Promise<SalesInvoiceDetailBackfillImportBatch> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<SalesInvoiceDetailBackfillImportBatch>>('/invoices/import-detail', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Commits the previously previewed batch — runs synchronously and returns the final counts. */
export async function resolveSalesInvoiceDetailBackfill(batchId: string): Promise<SalesInvoiceDetailBackfillResult> {
  const { data } = await apiClient.post<ApiResponse<SalesInvoiceDetailBackfillResult>>(`/invoices/import-detail/${batchId}/resolve`)
  return data.data
}
