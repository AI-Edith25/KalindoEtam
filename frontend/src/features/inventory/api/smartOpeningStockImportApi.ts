import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { SmartOpeningStockImportBatch } from '../types'

/**
 * Uploads a raw legacy export as-is -- SmartOpeningStockImportService detects the header row,
 * maps aliased columns, skips footer rows, and returns a preview. metadataCutoffDate is the
 * fallback Cutoff Date for rows whose own Date column is blank (e.g. a Stock Balance export
 * whose only date lives in a "Date From"/"Date To" metadata line, not per row).
 */
export async function storeSmartOpeningStockImport(file: File, metadataCutoffDate?: string): Promise<SmartOpeningStockImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  if (metadataCutoffDate) formData.append('metadata_cutoff_date', metadataCutoffDate)

  const { data } = await apiClient.post<ApiResponse<SmartOpeningStockImportBatch>>('/opening-stock/smart-import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Runs synchronously on the server and returns the finished result immediately -- no queued/processing state to poll for here. */
export async function resolveSmartOpeningStockImport(batchId: string): Promise<SmartOpeningStockImportBatch> {
  const { data } = await apiClient.post<ApiResponse<SmartOpeningStockImportBatch>>(`/opening-stock/smart-import/${batchId}/resolve`)
  return data.data
}
