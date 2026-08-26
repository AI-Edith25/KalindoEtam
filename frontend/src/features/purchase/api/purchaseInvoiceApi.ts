import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { PurchaseInvoice, PurchaseInvoiceFormValues } from '../types'

export interface PurchaseInvoiceListParams {
  page: number
  search?: string
  status?: string
  supplier_id?: string
  goods_receipt_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — mirrors sales/api/invoiceApi.ts's contract. */
export async function fetchPurchaseInvoices(params: PurchaseInvoiceListParams): Promise<ApiListResponse<PurchaseInvoice>> {
  const { data } = await apiClient.get<ApiListResponse<PurchaseInvoice>>('/purchase-invoices', { params })
  return data
}

export async function fetchPurchaseInvoice(id: string): Promise<PurchaseInvoice> {
  const { data } = await apiClient.get<ApiResponse<PurchaseInvoice>>(`/purchase-invoices/${id}`)
  return data.data
}

export async function createPurchaseInvoice(payload: PurchaseInvoiceFormValues): Promise<PurchaseInvoice> {
  const { data } = await apiClient.post<ApiResponse<PurchaseInvoice>>('/purchase-invoices', payload)
  return data.data
}

export async function updatePurchaseInvoice(id: string, payload: Partial<PurchaseInvoiceFormValues>): Promise<PurchaseInvoice> {
  const { data } = await apiClient.put<ApiResponse<PurchaseInvoice>>(`/purchase-invoices/${id}`, payload)
  return data.data
}

export async function deletePurchaseInvoice(id: string): Promise<void> {
  await apiClient.delete(`/purchase-invoices/${id}`)
}

export async function submitPurchaseInvoice(id: string): Promise<PurchaseInvoice> {
  const { data } = await apiClient.post<ApiResponse<PurchaseInvoice>>(`/purchase-invoices/${id}/submit`)
  return data.data
}

export async function cancelPurchaseInvoice(id: string): Promise<PurchaseInvoice> {
  const { data } = await apiClient.post<ApiResponse<PurchaseInvoice>>(`/purchase-invoices/${id}/cancel`)
  return data.data
}

/** Same filters as fetchPurchaseInvoices(), unpaginated, XLSX or CSV. */
export async function exportPurchaseInvoices(params: Omit<PurchaseInvoiceListParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/purchase-invoices/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
