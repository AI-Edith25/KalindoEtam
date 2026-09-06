import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { OpeningStock, OpeningStockFormValues } from '../types'

export interface OpeningStockListParams {
  page: number
  search?: string
  warehouse_id?: string
  status?: string
  date_from?: string
  date_to?: string
  import_batch_id?: string
  per_page?: number
}

export async function fetchOpeningStocks(params: OpeningStockListParams): Promise<ApiListResponse<OpeningStock>> {
  const { data } = await apiClient.get<ApiListResponse<OpeningStock>>('/opening-stocks', { params })
  return data
}

export async function fetchOpeningStock(id: string): Promise<OpeningStock> {
  const { data } = await apiClient.get<ApiResponse<OpeningStock>>(`/opening-stocks/${id}`)
  return data.data
}

export async function createOpeningStock(payload: OpeningStockFormValues): Promise<OpeningStock> {
  const { data } = await apiClient.post<ApiResponse<OpeningStock>>('/opening-stocks', payload)
  return data.data
}

export async function updateOpeningStock(id: string, payload: Partial<OpeningStockFormValues>): Promise<OpeningStock> {
  const { data } = await apiClient.put<ApiResponse<OpeningStock>>(`/opening-stocks/${id}`, payload)
  return data.data
}

export async function deleteOpeningStock(id: string): Promise<void> {
  await apiClient.delete(`/opening-stocks/${id}`)
}

export async function submitOpeningStock(id: string): Promise<OpeningStock> {
  const { data } = await apiClient.post<ApiResponse<OpeningStock>>(`/opening-stocks/${id}/submit`)
  return data.data
}

export async function cancelOpeningStock(id: string): Promise<OpeningStock> {
  const { data } = await apiClient.post<ApiResponse<OpeningStock>>(`/opening-stocks/${id}/cancel`)
  return data.data
}

export async function submitOpeningStockBatch(importBatchId: string): Promise<OpeningStock[]> {
  const { data } = await apiClient.post<ApiResponse<OpeningStock[]>>(`/opening-stocks/batches/${importBatchId}/submit`)
  return data.data
}

export async function cancelOpeningStockBatch(importBatchId: string): Promise<OpeningStock[]> {
  const { data } = await apiClient.post<ApiResponse<OpeningStock[]>>(`/opening-stocks/batches/${importBatchId}/cancel`)
  return data.data
}
