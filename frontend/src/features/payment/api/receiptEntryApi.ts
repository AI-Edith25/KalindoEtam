import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { DocumentStatus, PaymentMethod, ReceiptEntry } from '../types'

export interface ReceiptEntryListParams {
  page: number
  search?: string
  status?: DocumentStatus
  customer_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

export interface ReceiptEntryPayload {
  customer_id: string
  receipt_date: string
  payment_method: PaymentMethod
  reference_number: string | null
  remarks: string | null
  total_amount: number
}

/** Server-side paginated + filtered — mirrors paymentEntryApi.ts. No cancelReceiptEntry — the backend has no route. */
export async function fetchReceiptEntries(params: ReceiptEntryListParams): Promise<ApiListResponse<ReceiptEntry>> {
  const { data } = await apiClient.get<ApiListResponse<ReceiptEntry>>('/receipt-entries', { params })
  return data
}

export async function fetchReceiptEntry(id: string): Promise<ReceiptEntry> {
  const { data } = await apiClient.get<ApiResponse<ReceiptEntry>>(`/receipt-entries/${id}`)
  return data.data
}

export async function createReceiptEntry(payload: ReceiptEntryPayload): Promise<ReceiptEntry> {
  const { data } = await apiClient.post<ApiResponse<ReceiptEntry>>('/receipt-entries', payload)
  return data.data
}

export async function updateReceiptEntry(id: string, payload: Partial<ReceiptEntryPayload>): Promise<ReceiptEntry> {
  const { data } = await apiClient.put<ApiResponse<ReceiptEntry>>(`/receipt-entries/${id}`, payload)
  return data.data
}

export async function deleteReceiptEntry(id: string): Promise<void> {
  await apiClient.delete(`/receipt-entries/${id}`)
}

export async function submitReceiptEntry(id: string): Promise<ReceiptEntry> {
  const { data } = await apiClient.post<ApiResponse<ReceiptEntry>>(`/receipt-entries/${id}/submit`)
  return data.data
}
