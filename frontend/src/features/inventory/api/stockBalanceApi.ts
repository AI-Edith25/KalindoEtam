import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { StockBalanceRow, StockBalanceSummary } from '../types'

export interface StockBalanceReportParams {
  page: number
  search?: string
  warehouse_id?: string
  item_group_id?: string
  item_id?: string
  per_page?: number
}

/**
 * One row per (item, warehouse) with any ledger history — the Stock
 * Balance report. Distinct from stockApi.ts's fetchStockBalances (bulk
 * lookup by explicit item_ids, one warehouse, flat map) — same backend
 * controller, different endpoint and shape; kept in a separate file to
 * avoid confusing the two.
 */
export interface StockBalanceReportResponse extends ApiListResponse<StockBalanceRow> {
  meta: PaginationMeta & { summary: StockBalanceSummary }
}

export async function fetchStockBalanceReport(params: StockBalanceReportParams): Promise<StockBalanceReportResponse> {
  const { data } = await apiClient.get<StockBalanceReportResponse>('/stock-ledger/balances/report', { params })
  return data
}
