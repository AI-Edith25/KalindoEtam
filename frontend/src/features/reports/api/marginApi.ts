import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { MarginGroupBy, MarginKpis, MarginRow } from '../types'

export interface MarginParams {
  page: number
  per_page?: number
  customer_id?: string
  item_id?: string
  sales_person_id?: string
  branch_id?: string
  date_from?: string
  date_to?: string
  group?: MarginGroupBy
  sort?: 'amount' | 'cost_amount' | 'profit' | 'margin_pct' | 'qty' | 'item_name' | 'customer_name' | 'date' | 'document_number'
  sort_dir?: 'asc' | 'desc'
}

export interface MarginListResponse extends ApiListResponse<MarginRow> {
  meta: PaginationMeta & { kpis: MarginKpis }
}

export async function fetchMargin(params: MarginParams): Promise<MarginListResponse> {
  const { data } = await apiClient.get<MarginListResponse>('/reports/sales/margin', { params })
  return data
}

export async function exportMargin(params: Omit<MarginParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/sales/margin/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
