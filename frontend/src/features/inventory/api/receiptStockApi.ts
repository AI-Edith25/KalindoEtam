import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { ReceiptStock, ReceiptStockFormValues } from '../types'

export interface ReceiptStockListParams {
  page: number
  search?: string
  warehouse_id?: string
  status?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

export async function fetchReceiptStocks(params: ReceiptStockListParams): Promise<ApiListResponse<ReceiptStock>> {
  const { data } = await apiClient.get<ApiListResponse<ReceiptStock>>('/receipt-stocks', { params })
  return data
}

export async function fetchReceiptStock(id: string): Promise<ReceiptStock> {
  const { data } = await apiClient.get<ApiResponse<ReceiptStock>>(`/receipt-stocks/${id}`)
  return data.data
}

export async function createReceiptStock(payload: ReceiptStockFormValues): Promise<ReceiptStock> {
  const { data } = await apiClient.post<ApiResponse<ReceiptStock>>('/receipt-stocks', payload)
  return data.data
}

export async function updateReceiptStock(id: string, payload: Partial<ReceiptStockFormValues>): Promise<ReceiptStock> {
  const { data } = await apiClient.put<ApiResponse<ReceiptStock>>(`/receipt-stocks/${id}`, payload)
  return data.data
}

export async function deleteReceiptStock(id: string): Promise<void> {
  await apiClient.delete(`/receipt-stocks/${id}`)
}

export async function submitReceiptStock(id: string): Promise<ReceiptStock> {
  const { data } = await apiClient.post<ApiResponse<ReceiptStock>>(`/receipt-stocks/${id}/submit`)
  return data.data
}

export async function cancelReceiptStock(id: string): Promise<ReceiptStock> {
  const { data } = await apiClient.post<ApiResponse<ReceiptStock>>(`/receipt-stocks/${id}/cancel`)
  return data.data
}
