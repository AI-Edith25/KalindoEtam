import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { StockTransfer, StockTransferFormValues } from '../types'

export interface StockTransferListParams {
  page: number
  search?: string
  status?: string
  warehouse_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

export async function fetchStockTransfers(params: StockTransferListParams): Promise<ApiListResponse<StockTransfer>> {
  const { data } = await apiClient.get<ApiListResponse<StockTransfer>>('/stock-transfers', { params })
  return data
}

export async function fetchStockTransfer(id: string): Promise<StockTransfer> {
  const { data } = await apiClient.get<ApiResponse<StockTransfer>>(`/stock-transfers/${id}`)
  return data.data
}

export async function createStockTransfer(payload: StockTransferFormValues): Promise<StockTransfer> {
  const { data } = await apiClient.post<ApiResponse<StockTransfer>>('/stock-transfers', payload)
  return data.data
}

export async function updateStockTransfer(id: string, payload: Partial<StockTransferFormValues>): Promise<StockTransfer> {
  const { data } = await apiClient.put<ApiResponse<StockTransfer>>(`/stock-transfers/${id}`, payload)
  return data.data
}

export async function deleteStockTransfer(id: string): Promise<void> {
  await apiClient.delete(`/stock-transfers/${id}`)
}

export async function submitStockTransfer(id: string): Promise<StockTransfer> {
  const { data } = await apiClient.post<ApiResponse<StockTransfer>>(`/stock-transfers/${id}/submit`)
  return data.data
}

/** No cancelStockTransfer — the backend has no route. StockTransfer::cancel() always throws; reversal needs a compensating transfer (not yet implemented). */
