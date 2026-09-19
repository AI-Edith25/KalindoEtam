import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { GrossProfitGroupBy, GrossProfitKpis, GrossProfitRow } from '../types'

export interface GrossProfitParams {
  page: number
  per_page?: number
  customer_id?: string
  item_id?: string
  sales_person_id?: string
  branch_id?: string
  date_from?: string
  date_to?: string
  group?: GrossProfitGroupBy
  sort?: 'amount' | 'cost_amount' | 'profit' | 'margin_pct' | 'qty' | 'item_name' | 'customer_name' | 'date' | 'document_number'
  sort_dir?: 'asc' | 'desc'
}

export interface GrossProfitListResponse extends ApiListResponse<GrossProfitRow> {
  meta: PaginationMeta & { kpis: GrossProfitKpis }
}

export async function fetchGrossProfit(params: GrossProfitParams): Promise<GrossProfitListResponse> {
  const { data } = await apiClient.get<GrossProfitListResponse>('/reports/gross-profit', { params })
  return data
}

export async function exportGrossProfit(params: Omit<GrossProfitParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/gross-profit/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
