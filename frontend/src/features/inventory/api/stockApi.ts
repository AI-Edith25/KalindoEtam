import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'

export interface StockBalanceParams {
  warehouse_id: string
  item_ids: string[]
}

/** item_id -> current on-hand balance at that warehouse (source of truth: Stock Ledger, see backend StockLedgerService::getCurrentBalance()). */
export type StockBalances = Record<string, number>

/**
 * Bulk, warehouse-scoped current stock — added in Phase 2F for the
 * Delivery Editor's "Available Stock" column. Read-only pass-through to
 * an existing backend calculation; safe to reuse from any future editor
 * that needs to preview available stock before submitting (e.g. Stock
 * Adjustment).
 */
export async function fetchStockBalances(params: StockBalanceParams): Promise<StockBalances> {
  const { data } = await apiClient.get<ApiResponse<StockBalances>>('/stock-ledger/balances', { params })
  return data.data
}
