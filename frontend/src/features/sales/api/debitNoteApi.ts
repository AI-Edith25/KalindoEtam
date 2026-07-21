import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { DebitNote, DebitNoteFormValues } from '../types'

export interface DebitNoteListParams {
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

/** Server-side paginated + filtered — mirrors creditNoteApi.ts's contract. */
export async function fetchDebitNotes(params: DebitNoteListParams): Promise<ApiListResponse<DebitNote>> {
  const { data } = await apiClient.get<ApiListResponse<DebitNote>>('/debit-notes', { params })
  return data
}

export async function fetchDebitNote(id: string): Promise<DebitNote> {
  const { data } = await apiClient.get<ApiResponse<DebitNote>>(`/debit-notes/${id}`)
  return data.data
}

export async function createDebitNote(payload: DebitNoteFormValues): Promise<DebitNote> {
  const { data } = await apiClient.post<ApiResponse<DebitNote>>('/debit-notes', payload)
  return data.data
}

export async function updateDebitNote(id: string, payload: Partial<DebitNoteFormValues>): Promise<DebitNote> {
  const { data } = await apiClient.put<ApiResponse<DebitNote>>(`/debit-notes/${id}`, payload)
  return data.data
}

export async function deleteDebitNote(id: string): Promise<void> {
  await apiClient.delete(`/debit-notes/${id}`)
}

export async function submitDebitNote(id: string): Promise<DebitNote> {
  const { data } = await apiClient.post<ApiResponse<DebitNote>>(`/debit-notes/${id}/submit`)
  return data.data
}

export async function reverseDebitNote(id: string): Promise<DebitNote> {
  const { data } = await apiClient.post<ApiResponse<DebitNote>>(`/debit-notes/${id}/reverse`)
  return data.data
}
