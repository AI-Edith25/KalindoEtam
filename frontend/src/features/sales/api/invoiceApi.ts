import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Invoice, InvoiceFormValues } from '../types'

export interface InvoiceListParams {
  page: number
  search?: string
  status?: string
  customer_id?: string
  delivery_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — Invoice has IndexInvoiceRequest, mirroring Sales Order's and Delivery's contract. */
export async function fetchInvoices(params: InvoiceListParams): Promise<ApiListResponse<Invoice>> {
  const { data } = await apiClient.get<ApiListResponse<Invoice>>('/invoices', { params })
  return data
}

export async function fetchInvoice(id: string): Promise<Invoice> {
  const { data } = await apiClient.get<ApiResponse<Invoice>>(`/invoices/${id}`)
  return data.data
}

export async function createInvoice(payload: InvoiceFormValues): Promise<Invoice> {
  const { data } = await apiClient.post<ApiResponse<Invoice>>('/invoices', payload)
  return data.data
}

export async function updateInvoice(id: string, payload: Partial<InvoiceFormValues>): Promise<Invoice> {
  const { data } = await apiClient.put<ApiResponse<Invoice>>(`/invoices/${id}`, payload)
  return data.data
}

export async function deleteInvoice(id: string): Promise<void> {
  await apiClient.delete(`/invoices/${id}`)
}

export async function submitInvoice(id: string): Promise<Invoice> {
  const { data } = await apiClient.post<ApiResponse<Invoice>>(`/invoices/${id}/submit`)
  return data.data
}

export async function cancelInvoice(id: string): Promise<Invoice> {
  const { data } = await apiClient.post<ApiResponse<Invoice>>(`/invoices/${id}/cancel`)
  return data.data
}
