import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Delivery, DeliveryFormValues } from '../types'

export interface DeliveryListParams {
  page: number
  search?: string
  status?: string
  warehouse_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — Delivery has IndexDeliveryRequest, mirroring Sales Order's and Goods Receipt's contract. */
export async function fetchDeliveries(params: DeliveryListParams): Promise<ApiListResponse<Delivery>> {
  const { data } = await apiClient.get<ApiListResponse<Delivery>>('/deliveries', { params })
  return data
}

export async function fetchDelivery(id: string): Promise<Delivery> {
  const { data } = await apiClient.get<ApiResponse<Delivery>>(`/deliveries/${id}`)
  return data.data
}

export async function createDelivery(payload: DeliveryFormValues): Promise<Delivery> {
  const { data } = await apiClient.post<ApiResponse<Delivery>>('/deliveries', payload)
  return data.data
}

export async function updateDelivery(id: string, payload: Partial<DeliveryFormValues>): Promise<Delivery> {
  const { data } = await apiClient.put<ApiResponse<Delivery>>(`/deliveries/${id}`, payload)
  return data.data
}

export async function deleteDelivery(id: string): Promise<void> {
  await apiClient.delete(`/deliveries/${id}`)
}

export async function submitDelivery(id: string): Promise<Delivery> {
  const { data } = await apiClient.post<ApiResponse<Delivery>>(`/deliveries/${id}/submit`)
  return data.data
}

/** No cancelDelivery — the backend has no route. Delivery::cancel() always throws; reversal is only via the (not yet implemented) Return workflow. */
