import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { PoTrackingItemRow, PoTrackingRow, ReceivingStatus } from '../types'

export interface PoTrackingParams {
  page: number
  per_page?: number
  supplier_id?: string
  warehouse_id?: string
  date_from?: string
  date_to?: string
  receiving_status?: ReceivingStatus
  /** '0' | '1', not a real boolean — axios serializes a JS boolean into the literal string "true"/"false", which Laravel's own `boolean` validation rule rejects. */
  incomplete_only?: '0' | '1'
  sort?: 'order_date' | 'total_amount' | 'ordered_qty' | 'received_qty' | 'remaining_qty' | 'fulfillment_pct' | 'document_number'
  sort_dir?: 'asc' | 'desc'
}

export type PoTrackingListResponse = ApiListResponse<PoTrackingRow> & { meta: PaginationMeta }

export async function fetchPoTracking(params: PoTrackingParams): Promise<PoTrackingListResponse> {
  const { data } = await apiClient.get<PoTrackingListResponse>('/reports/purchase/po-tracking', { params })
  return data
}

export async function fetchPoTrackingItems(purchaseOrderId: string): Promise<PoTrackingItemRow[]> {
  const { data } = await apiClient.get<ApiResponse<PoTrackingItemRow[]>>(`/reports/purchase/po-tracking/${purchaseOrderId}/items`)
  return data.data
}

export async function exportPoTracking(params: Omit<PoTrackingParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/purchase/po-tracking/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
