import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Company, CompanyBranding, CompanyFormValues, DocumentAttachment } from '../types'

const COMPANY_ATTACHABLE_TYPE = 'App\\Models\\Company'

/** There is exactly one Company row today (docs/ADMINISTRATION_DESIGN.md §3) — the page always edits data[0], never a list. */
export async function fetchCurrentCompany(): Promise<Company | null> {
  const { data } = await apiClient.get<ApiListResponse<Company>>('/companies')
  return data.data[0] ?? null
}

/** Unguarded (no company.view needed) — name/logo only, for Header/Sidebar. Never call /companies for this. */
export async function fetchCompanyBranding(): Promise<CompanyBranding> {
  const { data } = await apiClient.get<ApiResponse<CompanyBranding>>('/company/branding')
  return data.data
}

export async function fetchCompanyBrandingLogoObjectUrl(): Promise<string> {
  const { data } = await apiClient.get('/company/branding/logo', { responseType: 'blob' })
  return URL.createObjectURL(data as Blob)
}

export async function updateCompany(id: string, payload: Partial<CompanyFormValues>): Promise<Company> {
  const { data } = await apiClient.put<ApiResponse<Company>>(`/companies/${id}`, payload)
  return data.data
}

export async function fetchCompanyLogo(companyId: string): Promise<DocumentAttachment | null> {
  const { data } = await apiClient.get<ApiListResponse<DocumentAttachment>>('/attachments', {
    params: { attachable_type: COMPANY_ATTACHABLE_TYPE, attachable_id: companyId },
  })
  // Latest wins — reuses the generic (many-attachments) DocumentAttachment system for a
  // conceptually-single Logo; see docs/ADMINISTRATION_DESIGN.md §3 Open Question 1.
  return data.data[0] ?? null
}

export async function uploadCompanyLogo(companyId: string, file: File): Promise<DocumentAttachment> {
  const formData = new FormData()
  formData.append('attachable_type', COMPANY_ATTACHABLE_TYPE)
  formData.append('attachable_id', companyId)
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<DocumentAttachment>>('/attachments', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function deleteCompanyLogo(attachmentId: string): Promise<void> {
  await apiClient.delete(`/attachments/${attachmentId}`)
}

/** Fetched as an authenticated blob (not a public URL) — attachments stay behind auth:sanctum like every other endpoint. See docs/ADMINISTRATION_DESIGN.md §3. */
export async function fetchCompanyLogoObjectUrl(attachmentId: string): Promise<string> {
  const { data } = await apiClient.get(`/attachments/${attachmentId}/download`, { responseType: 'blob' })
  return URL.createObjectURL(data as Blob)
}
