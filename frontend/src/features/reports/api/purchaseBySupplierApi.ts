import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { PurchaseBySupplierKpis, PurchaseBySupplierRow } from '../types'

export interface PurchaseBySupplierParams {
  page: number
  per_page?: number
  supplier_id?: string
  warehouse_id?: string
  date_from?: string
  date_to?: string
  sort?: 'amount' | 'qty' | 'receipt_count' | 'supplier_name'
  sort_dir?: 'asc' | 'desc'
}

export interface PurchaseBySupplierListResponse extends ApiListResponse<PurchaseBySupplierRow> {
  meta: PaginationMeta & { kpis: PurchaseBySupplierKpis }
}

export async function fetchPurchaseBySupplier(params: PurchaseBySupplierParams): Promise<PurchaseBySupplierListResponse> {
  const { data } = await apiClient.get<PurchaseBySupplierListResponse>('/reports/purchase/by-supplier', { params })
  return data
}

export async function exportPurchaseBySupplier(params: Omit<PurchaseBySupplierParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/purchase/by-supplier/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
