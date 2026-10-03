import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { SkybizReconciliationImportBatch } from '../types'

/**
 * Two-phase import (mandatory preview gate, unlike every other "smart" importer — this one
 * rewrites AR payment history across ~16k invoices). importSkybizLedger() only ever previews;
 * confirmSkybizLedgerImport() is the separate, explicit step that actually posts Official
 * Receipts. Poll fetchSkybizLedgerImportBatch() after each.
 */
export async function importSkybizLedger(file: File): Promise<SkybizReconciliationImportBatch> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<SkybizReconciliationImportBatch>>('/skybiz-ledger-reconciliation/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function confirmSkybizLedgerImport(batchId: string): Promise<SkybizReconciliationImportBatch> {
  const { data } = await apiClient.post<ApiResponse<SkybizReconciliationImportBatch>>(`/skybiz-ledger-reconciliation/import/${batchId}/confirm`)
  return data.data
}

export async function fetchSkybizLedgerImportBatch(batchId: string): Promise<SkybizReconciliationImportBatch> {
  const { data } = await apiClient.get<ApiResponse<SkybizReconciliationImportBatch>>(`/skybiz-ledger-reconciliation/import/${batchId}`)
  return data.data
}
