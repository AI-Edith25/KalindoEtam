import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { PurchaseReturn, PurchaseReturnFormValues } from '../types'

export interface PurchaseReturnListParams {
  page: number
  search?: string
  status?: string
  reason?: string
  supplier_id?: string
  purchase_invoice_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — mirrors sales/api/creditNoteApi.ts's contract. */
export async function fetchPurchaseReturns(params: PurchaseReturnListParams): Promise<ApiListResponse<PurchaseReturn>> {
  const { data } = await apiClient.get<ApiListResponse<PurchaseReturn>>('/purchase-returns', { params })
  return data
}

export async function fetchPurchaseReturn(id: string): Promise<PurchaseReturn> {
  const { data } = await apiClient.get<ApiResponse<PurchaseReturn>>(`/purchase-returns/${id}`)
  return data.data
}

export async function createPurchaseReturn(payload: PurchaseReturnFormValues): Promise<PurchaseReturn> {
  const { data } = await apiClient.post<ApiResponse<PurchaseReturn>>('/purchase-returns', payload)
  return data.data
}

export async function updatePurchaseReturn(id: string, payload: Partial<PurchaseReturnFormValues>): Promise<PurchaseReturn> {
  const { data } = await apiClient.put<ApiResponse<PurchaseReturn>>(`/purchase-returns/${id}`, payload)
  return data.data
}

export async function deletePurchaseReturn(id: string): Promise<void> {
  await apiClient.delete(`/purchase-returns/${id}`)
}

export async function submitPurchaseReturn(id: string): Promise<PurchaseReturn> {
  const { data } = await apiClient.post<ApiResponse<PurchaseReturn>>(`/purchase-returns/${id}/submit`)
  return data.data
}

export async function reversePurchaseReturn(id: string): Promise<PurchaseReturn> {
  const { data } = await apiClient.post<ApiResponse<PurchaseReturn>>(`/purchase-returns/${id}/reverse`)
  return data.data
}

/** Same filters as fetchPurchaseReturns(), unpaginated, XLSX or CSV. */
export async function exportPurchaseReturns(params: Omit<PurchaseReturnListParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/purchase-returns/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
