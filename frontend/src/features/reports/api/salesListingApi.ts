import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { PaymentStatus, SalesListingKpis, SalesListingRow, SalesListingType } from '../types'

export interface SalesListingParams {
  page: number
  per_page?: number
  search?: string
  customer_id?: string
  sales_person_id?: string
  branch_id?: string
  type?: SalesListingType
  payment_status?: PaymentStatus
  date_from?: string
  date_to?: string
  sort?: 'date' | 'document_number' | 'customer_name' | 'amount_incl_tax'
  sort_dir?: 'asc' | 'desc'
}

export interface SalesListingListResponse extends ApiListResponse<SalesListingRow> {
  meta: PaginationMeta & { kpis: SalesListingKpis }
}

export async function fetchSalesListing(params: SalesListingParams): Promise<SalesListingListResponse> {
  const { data } = await apiClient.get<SalesListingListResponse>('/reports/sales/listing', { params })
  return data
}

export async function exportSalesListing(params: Omit<SalesListingParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/sales/listing/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
