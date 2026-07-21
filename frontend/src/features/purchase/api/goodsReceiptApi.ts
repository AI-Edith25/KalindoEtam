import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { GoodsReceipt, GoodsReceiptFormValues } from '../types'

export interface GoodsReceiptListParams {
  page: number
  search?: string
  status?: string
  warehouse_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered, same shape as purchaseOrderApi.ts — see IndexGoodsReceiptRequest on the backend. */
export async function fetchGoodsReceipts(params: GoodsReceiptListParams): Promise<ApiListResponse<GoodsReceipt>> {
  const { data } = await apiClient.get<ApiListResponse<GoodsReceipt>>('/goods-receipts', { params })
  return data
}

export async function fetchGoodsReceipt(id: string): Promise<GoodsReceipt> {
  const { data } = await apiClient.get<ApiResponse<GoodsReceipt>>(`/goods-receipts/${id}`)
  return data.data
}

export async function createGoodsReceipt(payload: GoodsReceiptFormValues): Promise<GoodsReceipt> {
  const { data } = await apiClient.post<ApiResponse<GoodsReceipt>>('/goods-receipts', payload)
  return data.data
}

export async function updateGoodsReceipt(id: string, payload: Partial<GoodsReceiptFormValues>): Promise<GoodsReceipt> {
  const { data } = await apiClient.put<ApiResponse<GoodsReceipt>>(`/goods-receipts/${id}`, payload)
  return data.data
}

export async function deleteGoodsReceipt(id: string): Promise<void> {
  await apiClient.delete(`/goods-receipts/${id}`)
}

export async function submitGoodsReceipt(id: string): Promise<GoodsReceipt> {
  const { data } = await apiClient.post<ApiResponse<GoodsReceipt>>(`/goods-receipts/${id}/submit`)
  return data.data
}

/** No cancelGoodsReceipt — the backend has no route for it. GoodsReceipt::cancel() always throws; reversal is only via the (not yet implemented) Return workflow. */
