import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { DocumentStatus, PaymentEntry, PaymentMethod } from '../types'

export interface PaymentEntryListParams {
  page: number
  search?: string
  status?: DocumentStatus
  supplier_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

export interface PaymentEntryPayload {
  supplier_id: string
  payment_date: string
  payment_method: PaymentMethod
  reference_number: string | null
  remarks: string | null
  items: { accounts_payable_id: string; paid_amount: number }[]
}

/** Server-side paginated + filtered — mirrors deliveryApi.ts. No cancelPaymentEntry — the backend has no route. */
export async function fetchPaymentEntries(params: PaymentEntryListParams): Promise<ApiListResponse<PaymentEntry>> {
  const { data } = await apiClient.get<ApiListResponse<PaymentEntry>>('/payment-entries', { params })
  return data
}

export async function fetchPaymentEntry(id: string): Promise<PaymentEntry> {
  const { data } = await apiClient.get<ApiResponse<PaymentEntry>>(`/payment-entries/${id}`)
  return data.data
}

export async function createPaymentEntry(payload: PaymentEntryPayload): Promise<PaymentEntry> {
  const { data } = await apiClient.post<ApiResponse<PaymentEntry>>('/payment-entries', payload)
  return data.data
}

export async function updatePaymentEntry(id: string, payload: Partial<PaymentEntryPayload>): Promise<PaymentEntry> {
  const { data } = await apiClient.put<ApiResponse<PaymentEntry>>(`/payment-entries/${id}`, payload)
  return data.data
}

export async function deletePaymentEntry(id: string): Promise<void> {
  await apiClient.delete(`/payment-entries/${id}`)
}

export async function submitPaymentEntry(id: string): Promise<PaymentEntry> {
  const { data } = await apiClient.post<ApiResponse<PaymentEntry>>(`/payment-entries/${id}/submit`)
  return data.data
}
