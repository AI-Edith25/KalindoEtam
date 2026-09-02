import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { ItemPrice, ItemPriceFormValues } from '../types'

export interface ItemPriceImportSummary {
  created: number
  updated: number
  skipped: { row: number; reason: string }[]
}

function triggerBlobDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

/** Unpaginated — the item x zone override table is small, unlike the entity list pages that use createCrudApi. */
export async function fetchItemPrices(): Promise<ItemPrice[]> {
  const { data } = await apiClient.get<ApiResponse<ItemPrice[]>>('/item-prices')
  return data.data
}

export async function createItemPrice(payload: ItemPriceFormValues): Promise<ItemPrice> {
  const { data } = await apiClient.post<ApiResponse<ItemPrice>>('/item-prices', payload)
  return data.data
}

export async function updateItemPrice(id: string, rate: number): Promise<ItemPrice> {
  const { data } = await apiClient.put<ApiResponse<ItemPrice>>(`/item-prices/${id}`, { rate })
  return data.data
}

export async function deleteItemPrice(id: string): Promise<void> {
  await apiClient.delete(`/item-prices/${id}`)
}

export async function downloadItemPricesExport(): Promise<void> {
  const { data } = await apiClient.get('/item-prices/export', { responseType: 'blob' })
  triggerBlobDownload(data as Blob, 'item-prices-export.csv')
}

export async function importItemPrices(file: File): Promise<ItemPriceImportSummary> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<ItemPriceImportSummary>>('/item-prices/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}
