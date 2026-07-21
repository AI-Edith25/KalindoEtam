import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { SalesOrder, SalesOrderFormValues } from '../types'

export interface SalesOrderListParams {
  page: number
  search?: string
  status?: string
  customer_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — Sales Order has IndexSalesOrderRequest, mirroring Purchase Order's contract. */
export async function fetchSalesOrders(params: SalesOrderListParams): Promise<ApiListResponse<SalesOrder>> {
  const { data } = await apiClient.get<ApiListResponse<SalesOrder>>('/sales-orders', { params })
  return data
}

export async function fetchSalesOrder(id: string): Promise<SalesOrder> {
  const { data } = await apiClient.get<ApiResponse<SalesOrder>>(`/sales-orders/${id}`)
  return data.data
}

export async function createSalesOrder(payload: SalesOrderFormValues): Promise<SalesOrder> {
  const { data } = await apiClient.post<ApiResponse<SalesOrder>>('/sales-orders', payload)
  return data.data
}

export async function updateSalesOrder(id: string, payload: SalesOrderFormValues): Promise<SalesOrder> {
  const { data } = await apiClient.put<ApiResponse<SalesOrder>>(`/sales-orders/${id}`, payload)
  return data.data
}

export async function deleteSalesOrder(id: string): Promise<void> {
  await apiClient.delete(`/sales-orders/${id}`)
}

export async function submitSalesOrder(id: string): Promise<SalesOrder> {
  const { data } = await apiClient.post<ApiResponse<SalesOrder>>(`/sales-orders/${id}/submit`)
  return data.data
}

export async function cancelSalesOrder(id: string): Promise<SalesOrder> {
  const { data } = await apiClient.post<ApiResponse<SalesOrder>>(`/sales-orders/${id}/cancel`)
  return data.data
}
