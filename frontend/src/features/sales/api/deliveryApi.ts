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
  /** Omit/'detail' = the flat per-line DeliveryDetailExport (fixed 24-column contract); 'summary' = the legacy-report layout. */
  mode?: 'detail' | 'summary'
  ids?: string[]
  search?: string
  status?: string[]
  warehouse_id?: string
  customer_id?: string
  sales_person_id?: string
  sales_order_number?: string
  date_from?: string
  date_to?: string
  /** Mirrors whatever column the user has the on-screen table sorted by — detail mode only, see IndexDeliveryRequest. */
  sort_by?: 'delivery_date' | 'document_number'
  sort_direction?: 'asc' | 'desc'
}

/** Filename the server actually computed (from Content-Disposition), so the browser save dialog matches what's really in the file (e.g. the exported date range) instead of a client-side guess. Falls back to `fallback` if the header is missing/unreadable (e.g. CORS). */
function filenameFromResponse(headers: Record<string, unknown>, fallback: string): string {
  const disposition = String(headers['content-disposition'] ?? '')
  const match = /filename="?([^";]+)"?/i.exec(disposition)
  return match?.[1] ?? fallback
}

/** Bulk export — same filter contract as fetchDeliveries, plus `ids`/`sort_by`. See DeliveryController::export(). */
export async function exportDeliveries(params: DeliveryExportParams): Promise<{ blob: Blob; filename: string }> {
  const response = await apiClient.get('/deliveries/export', { params, responseType: 'blob' })
  return { blob: response.data, filename: filenameFromResponse(response.headers, `deliveries_export.${params.format}`) }
}
