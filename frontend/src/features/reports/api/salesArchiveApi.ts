import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { CustomerSalesParams, CustomerSalesListResponse } from './customerSalesApi'
import type { ProductSalesParams, ProductSalesListResponse } from './productSalesApi'
import type { SalesListingParams, SalesListingListResponse } from './salesListingApi'
import type {
  ProductSalesCustomerRow,
  SalesArchiveFileType,
  SalesArchiveHistoryEntry,
  SalesArchiveImportBatch,
  SalesArchiveMeta,
} from '../types'

export async function fetchSalesArchiveMeta(): Promise<SalesArchiveMeta> {
  const { data } = await apiClient.get<ApiResponse<SalesArchiveMeta>>('/sales-archive/meta')
  return data.data
}

export async function fetchSalesArchiveHistory(): Promise<SalesArchiveHistoryEntry[]> {
  const { data } = await apiClient.get<ApiResponse<SalesArchiveHistoryEntry[]>>('/sales-archive/history')
  return data.data
}

/** Preflight only -- parses and validates, returns a batch awaiting confirmation. */
export async function storeSalesArchiveSnapshot(file: File, fileType: SalesArchiveFileType): Promise<SalesArchiveImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('file_type', fileType)

  const { data } = await apiClient.post<ApiResponse<SalesArchiveImportBatch>>('/sales-archive/snapshots', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function resolveSalesArchiveImport(batchId: string): Promise<{ id: string }> {
  const { data } = await apiClient.post<ApiResponse<{ id: string }>>(`/sales-archive/batches/${batchId}/resolve`)
  return data.data
}

/** Same response shape as the live endpoint -- the backend shapes archive rows to match exactly, so the panel's columns/KPIs render unchanged. */
export async function fetchArchiveSalesListing(params: Omit<SalesListingParams, 'sort' | 'sort_dir'>): Promise<SalesListingListResponse> {
  const { data } = await apiClient.get<SalesListingListResponse>('/sales-archive/sales-listing', { params })
  return data
}

export async function exportArchiveSalesListing(params: Omit<SalesListingParams, 'page' | 'per_page' | 'sort' | 'sort_dir'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/sales-archive/sales-listing/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}

export async function fetchArchiveCustomerSales(params: Omit<CustomerSalesParams, 'sort' | 'sort_dir'>): Promise<CustomerSalesListResponse> {
  const { data } = await apiClient.get<CustomerSalesListResponse>('/sales-archive/customer-sales', { params })
  return data
}

export async function exportArchiveCustomerSales(params: Omit<CustomerSalesParams, 'page' | 'per_page' | 'sort' | 'sort_dir'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/sales-archive/customer-sales/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}

export async function fetchArchiveProductSales(params: Omit<ProductSalesParams, 'sort' | 'sort_dir'>): Promise<ProductSalesListResponse> {
  const { data } = await apiClient.get<ProductSalesListResponse>('/sales-archive/product-sales', { params })
  return data
}

export async function fetchArchiveProductSalesCustomers(itemCode: string, params: Omit<ProductSalesParams, 'page' | 'sort' | 'sort_dir'>): Promise<ProductSalesCustomerRow[]> {
  const { data } = await apiClient.get<ApiResponse<ProductSalesCustomerRow[]>>(`/sales-archive/product-sales/${itemCode}/customers`, { params })
  return data.data
}

export async function exportArchiveProductSales(params: Omit<ProductSalesParams, 'page' | 'per_page' | 'sort' | 'sort_dir'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/sales-archive/product-sales/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
