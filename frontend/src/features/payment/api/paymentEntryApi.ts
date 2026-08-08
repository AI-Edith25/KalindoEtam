import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { DocumentStatus, PaymentEntry, PaymentEntryType } from '../types'

export interface PaymentEntryListParams {
  page: number
  search?: string
  status?: DocumentStatus
  supplier_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/**
 * Covers both branches — Supplier fields (supplier_id/items) and General
 * Expense fields (expense_account_id/description/amount) are each optional
 * here since only one branch's fields are ever sent per payment_type; the
 * backend's StorePaymentEntryRequest enforces which are actually required.
 */
export interface PaymentEntryPayload {
  payment_type: PaymentEntryType
  supplier_id?: string | null
  items?: { accounts_payable_id: string; paid_amount: number }[]
  expense_account_id?: string | null
  description?: string | null
  amount?: number
  payment_date: string
  cash_account_id: string
  reference_number: string | null
  remarks: string | null
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
