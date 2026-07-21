import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { PurchaseOrder, PurchaseOrderFormValues } from '../types'

export interface PurchaseOrderListParams {
  page: number
  search?: string
  status?: string
  supplier_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — Purchase Order now has IndexPurchaseOrderRequest, unlike the master-data list endpoints. */
export async function fetchPurchaseOrders(params: PurchaseOrderListParams): Promise<ApiListResponse<PurchaseOrder>> {
  const { data } = await apiClient.get<ApiListResponse<PurchaseOrder>>('/purchase-orders', { params })
  return data
}

export async function fetchPurchaseOrder(id: string): Promise<PurchaseOrder> {
  const { data } = await apiClient.get<ApiResponse<PurchaseOrder>>(`/purchase-orders/${id}`)
  return data.data
}

export async function createPurchaseOrder(payload: PurchaseOrderFormValues): Promise<PurchaseOrder> {
  const { data } = await apiClient.post<ApiResponse<PurchaseOrder>>('/purchase-orders', payload)
  return data.data
}

export async function updatePurchaseOrder(id: string, payload: PurchaseOrderFormValues): Promise<PurchaseOrder> {
  const { data } = await apiClient.put<ApiResponse<PurchaseOrder>>(`/purchase-orders/${id}`, payload)
  return data.data
}

export async function deletePurchaseOrder(id: string): Promise<void> {
  await apiClient.delete(`/purchase-orders/${id}`)
}

export async function submitPurchaseOrder(id: string): Promise<PurchaseOrder> {
  const { data } = await apiClient.post<ApiResponse<PurchaseOrder>>(`/purchase-orders/${id}/submit`)
  return data.data
}

export async function cancelPurchaseOrder(id: string): Promise<PurchaseOrder> {
  const { data } = await apiClient.post<ApiResponse<PurchaseOrder>>(`/purchase-orders/${id}/cancel`)
  return data.data
}
