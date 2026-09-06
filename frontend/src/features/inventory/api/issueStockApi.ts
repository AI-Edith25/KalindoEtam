import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { IssueStock, IssueStockFormValues } from '../types'

export interface IssueStockListParams {
  page: number
  search?: string
  warehouse_id?: string
  status?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

export async function fetchIssueStocks(params: IssueStockListParams): Promise<ApiListResponse<IssueStock>> {
  const { data } = await apiClient.get<ApiListResponse<IssueStock>>('/issue-stocks', { params })
  return data
}

export async function fetchIssueStock(id: string): Promise<IssueStock> {
  const { data } = await apiClient.get<ApiResponse<IssueStock>>(`/issue-stocks/${id}`)
  return data.data
}

export async function createIssueStock(payload: IssueStockFormValues): Promise<IssueStock> {
  const { data } = await apiClient.post<ApiResponse<IssueStock>>('/issue-stocks', payload)
  return data.data
}

export async function updateIssueStock(id: string, payload: Partial<IssueStockFormValues>): Promise<IssueStock> {
  const { data } = await apiClient.put<ApiResponse<IssueStock>>(`/issue-stocks/${id}`, payload)
  return data.data
}

export async function deleteIssueStock(id: string): Promise<void> {
  await apiClient.delete(`/issue-stocks/${id}`)
}

export async function submitIssueStock(id: string): Promise<IssueStock> {
  const { data } = await apiClient.post<ApiResponse<IssueStock>>(`/issue-stocks/${id}/submit`)
  return data.data
}

export async function cancelIssueStock(id: string): Promise<IssueStock> {
  const { data } = await apiClient.post<ApiResponse<IssueStock>>(`/issue-stocks/${id}/cancel`)
  return data.data
}

/** Live FIFO-computed Unit Cost for the editor's read-only column, before Submit. */
export async function previewIssueStockCost(itemId: string, warehouseId: string, qty: number): Promise<{ unit_cost: number; available_qty: number }> {
  const { data } = await apiClient.get<ApiResponse<{ unit_cost: number; available_qty: number }>>('/issue-stocks/preview-cost', {
    params: { item_id: itemId, warehouse_id: warehouseId, qty },
  })
  return data.data
}
