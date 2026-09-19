import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  PurchaseHistoryImportBatch,
  PurchaseHistoryImportType,
  PurchaseHistoryResolutionAction,
  PurchaseHistoryResolutionCategory,
} from '../types'

export interface PurchaseHistoryResolutionInput {
  category: PurchaseHistoryResolutionCategory
  value: string
  action: PurchaseHistoryResolutionAction
  target_id?: string | null
}

/**
 * Uploads the file for one Purchase Report tab's own Import button — `expectedType` is that
 * button's promise; the server still auto-detects the file's real type (that's what makes the
 * pre-import summary possible) but rejects a mismatch instead of silently processing it under the
 * wrong button. warehouseId/placeholderItemId are only real input for the 2 types that fabricate a
 * placeholder line (Supplier Purchase Listing / Purchase Order Tracking) — omit whichever the
 * caller's type doesn't need.
 */
export async function storePurchaseHistoryImport(
  file: File,
  expectedType: PurchaseHistoryImportType,
  warehouseId?: string,
  placeholderItemId?: string,
): Promise<PurchaseHistoryImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('expected_type', expectedType)
  if (warehouseId) formData.append('warehouse_id', warehouseId)
  if (placeholderItemId) formData.append('placeholder_item_id', placeholderItemId)

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
