import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { JournalEntry, JournalEntryFormValues } from '../types'

export interface JournalEntryListParams {
  page: number
  search?: string
  status?: string
  reference_type?: string
  account_id?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

export async function fetchJournalEntries(params: JournalEntryListParams): Promise<ApiListResponse<JournalEntry>> {
  const { data } = await apiClient.get<ApiListResponse<JournalEntry>>('/journal-entries', { params })
  return data
}

export async function fetchJournalEntry(id: string): Promise<JournalEntry> {
  const { data } = await apiClient.get<ApiResponse<JournalEntry>>(`/journal-entries/${id}`)
  return data.data
}

export async function createJournalEntry(payload: JournalEntryFormValues): Promise<JournalEntry> {
  const { data } = await apiClient.post<ApiResponse<JournalEntry>>('/journal-entries', payload)
  return data.data
}

export async function updateJournalEntry(id: string, payload: Partial<JournalEntryFormValues>): Promise<JournalEntry> {
  const { data } = await apiClient.put<ApiResponse<JournalEntry>>(`/journal-entries/${id}`, payload)
  return data.data
}

export async function deleteJournalEntry(id: string): Promise<void> {
  await apiClient.delete(`/journal-entries/${id}`)
}

export async function postJournalEntry(id: string): Promise<JournalEntry> {
  const { data } = await apiClient.post<ApiResponse<JournalEntry>>(`/journal-entries/${id}/post`)
  return data.data
}

export async function reverseJournalEntry(id: string): Promise<JournalEntry> {
  const { data } = await apiClient.post<ApiResponse<JournalEntry>>(`/journal-entries/${id}/reverse`)
  return data.data
}
