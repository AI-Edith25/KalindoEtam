import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Invoice, InvoiceChangeRequest } from '../types'

export async function fetchInvoiceChangeRequests(invoiceId: string): Promise<InvoiceChangeRequest[]> {
  const { data } = await apiClient.get<ApiListResponse<InvoiceChangeRequest>>('/invoice-change-requests', {
    params: { invoice_id: invoiceId },
  })
  return data.data
}

export async function requestInvoiceChange(invoiceId: string, requestReason: string): Promise<InvoiceChangeRequest> {
  const { data } = await apiClient.post<ApiResponse<InvoiceChangeRequest>>('/invoice-change-requests', {
    invoice_id: invoiceId,
    request_reason: requestReason,
  })
  return data.data
}

export async function approveInvoiceChangeRequest(id: string, remarks?: string): Promise<InvoiceChangeRequest> {
  const { data } = await apiClient.post<ApiResponse<InvoiceChangeRequest>>(`/invoice-change-requests/${id}/approve`, { remarks })
  return data.data
}

export async function rejectInvoiceChangeRequest(id: string, remarks: string): Promise<InvoiceChangeRequest> {
  const { data } = await apiClient.post<ApiResponse<InvoiceChangeRequest>>(`/invoice-change-requests/${id}/reject`, { remarks })
  return data.data
}

export async function applyInvoiceNominalChange(id: string, items: { id: string; rate: number }[]): Promise<Invoice> {
  const { data } = await apiClient.put<ApiResponse<Invoice>>(`/invoice-change-requests/${id}/nominal`, { items })
  return data.data
}
