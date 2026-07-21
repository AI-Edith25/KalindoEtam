import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse } from '@/shared/types/api'
import type { StockBalanceRow } from '../types'

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
export async function fetchStockBalanceReport(params: StockBalanceReportParams): Promise<ApiListResponse<StockBalanceRow>> {
  const { data } = await apiClient.get<ApiListResponse<StockBalanceRow>>('/stock-ledger/balances/report', { params })
  return data
}
