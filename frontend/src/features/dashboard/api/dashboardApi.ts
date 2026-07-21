import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type {
  FinancialSummary,
  InventoryMovementPoint,
  LowStockItem,
  OutstandingSummary,
  PendingTask,
  RecentTransaction,
  StockSummary,
  TrendPoint,
} from '../types'

export async function fetchStockSummary(): Promise<StockSummary> {
  const { data } = await apiClient.get<ApiResponse<StockSummary>>('/dashboard/stock-summary')
  return data.data
}

export async function fetchSalesToday(): Promise<{ date: string; total_amount: number; count: number }> {
  const { data } = await apiClient.get<ApiResponse<{ date: string; total_amount: number; count: number }>>('/dashboard/sales-today')
  return data.data
}

export async function fetchPurchasesToday(): Promise<{ date: string; total_amount: number; count: number }> {
  const { data } = await apiClient.get<ApiResponse<{ date: string; total_amount: number; count: number }>>('/dashboard/purchases-today')
  return data.data
}

export async function fetchFinancialSummary(params?: { date_from: string; date_to: string }): Promise<FinancialSummary> {
  const { data } = await apiClient.get<ApiResponse<FinancialSummary>>('/dashboard/financial-summary', { params })
  return data.data
}

export async function fetchPendingTasks(): Promise<PendingTask[]> {
  const { data } = await apiClient.get<ApiResponse<PendingTask[]>>('/dashboard/pending-tasks')
  return data.data
}

export async function fetchSalesTrend(): Promise<TrendPoint[]> {
  const { data } = await apiClient.get<ApiResponse<TrendPoint[]>>('/dashboard/sales-trend')
  return data.data
}

export async function fetchPurchaseTrend(): Promise<TrendPoint[]> {
  const { data } = await apiClient.get<ApiResponse<TrendPoint[]>>('/dashboard/purchase-trend')
  return data.data
}

export async function fetchInventoryMovement(): Promise<InventoryMovementPoint[]> {
  const { data } = await apiClient.get<ApiResponse<InventoryMovementPoint[]>>('/dashboard/inventory-movement')
  return data.data
}

export async function fetchAccountsPayableOutstanding(): Promise<OutstandingSummary> {
  const { data } = await apiClient.get<ApiResponse<OutstandingSummary>>('/dashboard/accounts-payable-outstanding')
  return data.data
}

export async function fetchAccountsReceivableOutstanding(): Promise<OutstandingSummary> {
  const { data } = await apiClient.get<ApiResponse<OutstandingSummary>>('/dashboard/accounts-receivable-outstanding')
  return data.data
}

export async function fetchLowStockItems(threshold: number): Promise<ApiListResponse<LowStockItem>> {
  const { data } = await apiClient.get<ApiListResponse<LowStockItem>>('/dashboard/low-stock-items', {
    params: { threshold },
  })
  return data
}

export async function fetchRecentTransactions(limit: number): Promise<RecentTransaction[]> {
  const { data } = await apiClient.get<ApiResponse<RecentTransaction[]>>('/dashboard/recent-transactions', {
    params: { limit },
  })
  return data.data
}
