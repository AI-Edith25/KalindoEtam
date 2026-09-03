import { apiClient } from '@/shared/services/apiClient'
import { triggerBlobDownload } from '@/shared/services/downloadFile'
import type { ApiResponse } from '@/shared/types/api'
import type { ItemWarehousePrice, ItemWarehousePriceCell, ItemWarehousePriceCellResult } from '../types'

export interface ItemWarehousePriceImportPreview {
  to_create: number
  to_update: number
  to_delete: number
  sync_changes: number
  unchanged: number
  errors: { row: number; reason: string }[]
}

export interface ItemWarehousePriceImportSummary {
  cells_applied: number
  sync_changes: number
  errors: { row: number; reason: string }[]
}

/** Unpaginated — the item x warehouse override table is small, bounded by items×warehouses. */
export async function fetchItemWarehousePrices(): Promise<ItemWarehousePrice[]> {
  const { data } = await apiClient.get<ApiResponse<ItemWarehousePrice[]>>('/item-warehouse-prices')
  return data.data
}

/** Every write — including a single cell — goes through this one batch endpoint. */
export async function bulkUpdateItemWarehousePrices(cells: ItemWarehousePriceCell[]): Promise<ItemWarehousePriceCellResult[]> {
  const { data } = await apiClient.post<ApiResponse<ItemWarehousePriceCellResult[]>>('/item-warehouse-prices/bulk', { cells })
  return data.data
}

export async function downloadItemWarehousePricesExport(): Promise<void> {
  const { data } = await apiClient.get('/item-warehouse-prices/export', { responseType: 'blob' })
  triggerBlobDownload(data as Blob, 'item-warehouse-prices-export.csv')
}

export async function previewItemWarehousePricesImport(file: File): Promise<ItemWarehousePriceImportPreview> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<ItemWarehousePriceImportPreview>>('/item-warehouse-prices/import-preview', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function commitItemWarehousePricesImport(file: File): Promise<ItemWarehousePriceImportSummary> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<ItemWarehousePriceImportSummary>>('/item-warehouse-prices/import-commit', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function bulkSetSyncToMainWh(itemIds: string[], value: boolean): Promise<void> {
  await apiClient.post('/items/bulk-sync-to-main-wh', { item_ids: itemIds, value })
}
