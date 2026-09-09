import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { StockValuationRow, StockValuationSummary } from '../types'

export interface StockValuationReportParams {
  page: number
  date_from: string
  date_to: string
  search?: string
  warehouse_id?: string
  item_group_id?: string
  item_id?: string
  per_page?: number
}

export interface StockValuationReportResponse extends ApiListResponse<StockValuationRow> {
  meta: PaginationMeta & { summary: StockValuationSummary }
}

export async function fetchStockValuationReport(params: StockValuationReportParams): Promise<StockValuationReportResponse> {
  const { data } = await apiClient.get<StockValuationReportResponse>('/inventory-valuation', { params })
  return data
}

/** Blob response, same auth-header rule as exportAccountsReceivableLedger() — the download link needs the request to carry the bearer token, so a plain <a href> won't work. */
export async function exportStockValuationReport(params: Omit<StockValuationReportParams, 'page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/inventory-valuation/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
