import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { AgingBucket, OpenOrdersKpis, OpenOrdersRow } from '../types'

export interface OpenOrdersParams {
  page: number
  per_page?: number
  search?: string
  status?: string
  customer_id?: string
  item_id?: string
  item_group_id?: string
  sales_person_id?: string
  branch_id?: string
  date_from?: string
  date_to?: string
  aging?: AgingBucket
  overdue_only?: boolean
  sort?: 'order_date' | 'document_number' | 'customer_name' | 'item_name' | 'qty_outstanding' | 'outstanding_value'
  sort_dir?: 'asc' | 'desc'
}

export interface OpenOrdersListResponse extends ApiListResponse<OpenOrdersRow> {
  meta: PaginationMeta & { kpis: OpenOrdersKpis }
}

export async function fetchOpenOrders(params: OpenOrdersParams): Promise<OpenOrdersListResponse> {
  const { data } = await apiClient.get<OpenOrdersListResponse>('/reports/sales/open-orders', { params })
  return data
}

export async function exportOpenOrders(params: Omit<OpenOrdersParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/sales/open-orders/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
