import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  PurchaseHistoryImportBatch,
  PurchaseHistoryResolutionAction,
  PurchaseHistoryResolutionCategory,
} from '../types'

export interface PurchaseHistoryResolutionInput {
  category: PurchaseHistoryResolutionCategory
  value: string
  action: PurchaseHistoryResolutionAction
  target_id?: string | null
}

/** Uploads the file — server auto-detects its type and returns either a queued batch (nothing to resolve) or a `previewed` one carrying `preview_summary.needs_resolution`. */
export async function storePurchaseHistoryImport(file: File, warehouseId: string, placeholderItemId: string): Promise<PurchaseHistoryImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('warehouse_id', warehouseId)
  formData.append('placeholder_item_id', placeholderItemId)

  const { data } = await apiClient.post<ApiResponse<PurchaseHistoryImportBatch>>('/purchase-history/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function resolvePurchaseHistoryImport(
  batchId: string,
  resolutions: PurchaseHistoryResolutionInput[],
): Promise<PurchaseHistoryImportBatch> {
  const { data } = await apiClient.post<ApiResponse<PurchaseHistoryImportBatch>>(`/purchase-history/import/${batchId}/resolve`, { resolutions })
  return data.data
}

export async function fetchPurchaseHistoryImportBatch(batchId: string): Promise<PurchaseHistoryImportBatch> {
  const { data } = await apiClient.get<ApiResponse<PurchaseHistoryImportBatch>>(`/purchase-history/import/${batchId}`)
  return data.data
}
