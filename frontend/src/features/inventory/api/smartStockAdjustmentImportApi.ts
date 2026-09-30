import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { SmartStockAdjustmentImportBatch } from '../types'

/**
 * Uploads a raw legacy stock-snapshot export as-is -- same file shapes Smart Opening Stock
 * Import accepts, read here as a COUNTED balance to reconcile current stock to, not a new
 * opening balance. metadataAdjustmentDate is the fallback Adjustment Date for rows whose own
 * Date column is blank (e.g. a Stock Balance export whose only date lives in a "Date From"/"Date
 * To" metadata line, not per row).
 */
export async function storeSmartStockAdjustmentImport(file: File, metadataAdjustmentDate?: string): Promise<SmartStockAdjustmentImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  if (metadataAdjustmentDate) formData.append('metadata_adjustment_date', metadataAdjustmentDate)

  const { data } = await apiClient.post<ApiResponse<SmartStockAdjustmentImportBatch>>('/stock-adjustments/smart-import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Runs synchronously on the server and returns the finished result immediately -- no queued/processing state to poll for here. */
export async function resolveSmartStockAdjustmentImport(batchId: string): Promise<SmartStockAdjustmentImportBatch> {
  const { data } = await apiClient.post<ApiResponse<SmartStockAdjustmentImportBatch>>(`/stock-adjustments/smart-import/${batchId}/resolve`)
  return data.data
}
