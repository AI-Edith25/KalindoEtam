import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { DocumentAttachment } from '@/features/administration/types'

/** D1 (UAT review 2026-08-12) — Incoming Payment proof-of-transfer, reusing the generic DocumentAttachment system (ReceiptEntry already has attachable() via the Documentable trait) — same shape as companyApi.ts's logo upload, just a different attachable_type. */
const RECEIPT_ENTRY_ATTACHABLE_TYPE = 'App\\Models\\ReceiptEntry'

export async function fetchReceiptEntryAttachments(receiptEntryId: string): Promise<DocumentAttachment[]> {
  const { data } = await apiClient.get<ApiListResponse<DocumentAttachment>>('/attachments', {
    params: { attachable_type: RECEIPT_ENTRY_ATTACHABLE_TYPE, attachable_id: receiptEntryId },
  })
  return data.data
}

export async function uploadReceiptEntryAttachment(receiptEntryId: string, file: File): Promise<DocumentAttachment> {
  const formData = new FormData()
  formData.append('attachable_type', RECEIPT_ENTRY_ATTACHABLE_TYPE)
  formData.append('attachable_id', receiptEntryId)
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<DocumentAttachment>>('/attachments', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Fetched as an authenticated blob, not a public URL — attachments stay behind auth:sanctum. */
export async function fetchReceiptEntryAttachmentObjectUrl(attachmentId: string): Promise<string> {
  const { data } = await apiClient.get(`/attachments/${attachmentId}/download`, { responseType: 'blob' })
  return URL.createObjectURL(data as Blob)
}
