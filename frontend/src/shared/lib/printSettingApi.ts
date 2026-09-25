import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { PrintOptions } from './printOptions'

/** Delivery Order + Invoice only for now — see PrintSettingService::DOCUMENT_TYPES on the backend. */
export type PrintSettingDocumentType = 'delivery-order' | 'invoice'

type PrintSettingsByDocument = Record<PrintSettingDocumentType, Partial<PrintOptions> | null>

/** Current user's own server-saved print settings — server > localStorage > default priority (see printOptions.ts's load functions). */
export async function fetchMyPrintSettings(): Promise<PrintSettingsByDocument> {
  const { data } = await apiClient.get<ApiResponse<PrintSettingsByDocument>>('/print-settings')
  return data.data
}

export async function saveMyPrintSetting(documentType: PrintSettingDocumentType, settings: Partial<PrintOptions>): Promise<void> {
  await apiClient.put(`/print-settings/${documentType}`, settings)
}

/** Admin-only — trial-and-error another user's Print Options (dot-matrix tuning etc.), gated by administration.print_settings.*. */
export async function fetchUserPrintSettings(userId: string): Promise<PrintSettingsByDocument> {
  const { data } = await apiClient.get<ApiResponse<PrintSettingsByDocument>>(`/users/${userId}/print-settings`)
  return data.data
}

export async function saveUserPrintSetting(userId: string, documentType: PrintSettingDocumentType, settings: Partial<PrintOptions>): Promise<void> {
  await apiClient.put(`/users/${userId}/print-settings/${documentType}`, settings)
}
