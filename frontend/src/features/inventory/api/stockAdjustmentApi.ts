import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { StockAdjustment, StockAdjustmentFormValues } from '../types'

export interface StockAdjustmentListParams {
  page: number
  search?: string
  status?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

export async function fetchStockAdjustments(params: StockAdjustmentListParams): Promise<ApiListResponse<StockAdjustment>> {
  const { data } = await apiClient.get<ApiListResponse<StockAdjustment>>('/stock-adjustments', { params })
  return data
}

export async function fetchStockAdjustment(id: string): Promise<StockAdjustment> {
  const { data } = await apiClient.get<ApiResponse<StockAdjustment>>(`/stock-adjustments/${id}`)
  return data.data
}

export async function createStockAdjustment(payload: StockAdjustmentFormValues): Promise<StockAdjustment> {
  const { data } = await apiClient.post<ApiResponse<StockAdjustment>>('/stock-adjustments', payload)
  return data.data
}

export async function updateStockAdjustment(id: string, payload: Partial<StockAdjustmentFormValues>): Promise<StockAdjustment> {
  const { data } = await apiClient.put<ApiResponse<StockAdjustment>>(`/stock-adjustments/${id}`, payload)
  return data.data
}

export async function deleteStockAdjustment(id: string): Promise<void> {
  await apiClient.delete(`/stock-adjustments/${id}`)
}

export async function submitStockAdjustment(id: string): Promise<StockAdjustment> {
  const { data } = await apiClient.post<ApiResponse<StockAdjustment>>(`/stock-adjustments/${id}/submit`)
  return data.data
}

/** No cancelStockAdjustment — the backend has no route. StockAdjustment::cancel() always throws; reversal needs a compensating adjustment (not yet implemented). */
