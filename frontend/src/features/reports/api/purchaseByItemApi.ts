import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { PurchaseByItemHistoryRow, PurchaseByItemRow } from '../types'

export interface PurchaseByItemParams {
  page: number
  per_page?: number
  supplier_id?: string
  warehouse_id?: string
  date_from?: string
  date_to?: string
  sort?: 'amount' | 'qty' | 'item_name' | 'avg_price' | 'last_price' | 'lowest_price' | 'highest_price'
  sort_dir?: 'asc' | 'desc'
}

export type PurchaseByItemListResponse = ApiListResponse<PurchaseByItemRow> & { meta: PaginationMeta }

export async function fetchPurchaseByItem(params: PurchaseByItemParams): Promise<PurchaseByItemListResponse> {
  const { data } = await apiClient.get<PurchaseByItemListResponse>('/reports/purchase/by-item', { params })
  return data
}

export async function fetchPurchaseByItemHistory(itemId: string, params: Omit<PurchaseByItemParams, 'page' | 'per_page'>): Promise<PurchaseByItemHistoryRow[]> {
  const { data } = await apiClient.get<ApiResponse<PurchaseByItemHistoryRow[]>>(`/reports/purchase/by-item/${itemId}/history`, { params })
  return data.data
}

export async function exportPurchaseByItem(params: Omit<PurchaseByItemParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/purchase/by-item/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
