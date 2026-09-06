import { apiClient } from '@/shared/services/apiClient'
import type { PaginationMeta } from '@/shared/types/api'
import type { FifoValuationGroup, FifoValuationSummary } from '../types'

export interface FifoValuationListParams {
  page?: number
  warehouse_id?: string
  item_group_id?: string
  item_id?: string
  date_from?: string
  date_to?: string
  hide_exhausted?: 'true'
  per_page?: number
}

export interface FifoValuationListResponse {
  success: boolean
  message: string
  data: FifoValuationGroup[]
  meta: PaginationMeta & { summary: FifoValuationSummary }
}

export async function fetchFifoValuation(params: FifoValuationListParams): Promise<FifoValuationListResponse> {
  const { data } = await apiClient.get<FifoValuationListResponse>('/fifo-layers', { params })
  return data
}

export async function exportFifoValuation(params: Omit<FifoValuationListParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/fifo-layers/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
