import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { CreditNote, CreditNoteFormValues } from '../types'

export interface CreditNoteListParams {
  page: number
  search?: string
  status?: string
  reason?: string
  customer_id?: string
  invoice_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — mirrors invoiceApi.ts's contract. */
export async function fetchCreditNotes(params: CreditNoteListParams): Promise<ApiListResponse<CreditNote>> {
  const { data } = await apiClient.get<ApiListResponse<CreditNote>>('/credit-notes', { params })
  return data
}

export async function fetchCreditNote(id: string): Promise<CreditNote> {
  const { data } = await apiClient.get<ApiResponse<CreditNote>>(`/credit-notes/${id}`)
  return data.data
}

export async function createCreditNote(payload: CreditNoteFormValues): Promise<CreditNote> {
  const { data } = await apiClient.post<ApiResponse<CreditNote>>('/credit-notes', payload)
  return data.data
}

export async function updateCreditNote(id: string, payload: Partial<CreditNoteFormValues>): Promise<CreditNote> {
  const { data } = await apiClient.put<ApiResponse<CreditNote>>(`/credit-notes/${id}`, payload)
  return data.data
}

export async function deleteCreditNote(id: string): Promise<void> {
  await apiClient.delete(`/credit-notes/${id}`)
}

export async function submitCreditNote(id: string): Promise<CreditNote> {
  const { data } = await apiClient.post<ApiResponse<CreditNote>>(`/credit-notes/${id}/submit`)
  return data.data
}

export async function reverseCreditNote(id: string): Promise<CreditNote> {
  const { data } = await apiClient.post<ApiResponse<CreditNote>>(`/credit-notes/${id}/reverse`)
  return data.data
}
