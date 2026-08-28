import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Delivery, DeliveryFormValues } from '../types'

export interface DeliveryListParams {
  page: number
  search?: string
  status?: string | string[]
  warehouse_id?: string
  customer_id?: string
  sales_person_id?: string
  item_id?: string
  sales_order_number?: string
  date_from?: string
  date_to?: string
  per_page?: number
  outstanding?: boolean
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

export async function completeDelivery(id: string): Promise<Delivery> {
  const { data } = await apiClient.post<ApiResponse<Delivery>>(`/deliveries/${id}/complete`)
  return data.data
}

/** No cancelDelivery — the backend has no route. Delivery::cancel() always throws; reversal is only via the (not yet implemented) Return workflow. */

export interface DeliveryExportParams {
  format: 'xlsx' | 'csv'
  columns?: string[]
  ids?: string[]
  search?: string
  status?: string[]
  warehouse_id?: string
  customer_id?: string
  sales_person_id?: string
  sales_order_number?: string
  date_from?: string
  date_to?: string
}

/** Bulk export — same filter contract as fetchDeliveries, plus `ids`/`columns`. See DeliveryController::export(). */
export async function exportDeliveries(params: DeliveryExportParams): Promise<Blob> {
  const { data } = await apiClient.get('/deliveries/export', { params, responseType: 'blob' })
  return data
}
