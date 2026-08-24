import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { ProductSalesCustomerRow, ProductSalesKpis, ProductSalesRow, SalesReportGroupBy } from '../types'

export interface ProductSalesParams {
  page: number
  per_page?: number
  search?: string
  status?: string
  customer_id?: string
  item_id?: string
  item_group_id?: string
  sales_person_id?: string
  branch_id?: string
  date_from?: string
  date_to?: string
  group?: SalesReportGroupBy
  sort?: 'amount' | 'qty' | 'item_name'
  sort_dir?: 'asc' | 'desc'
}

export interface ProductSalesListResponse extends ApiListResponse<ProductSalesRow> {
  meta: PaginationMeta & { kpis: ProductSalesKpis }
}

export async function fetchProductSales(params: ProductSalesParams): Promise<ProductSalesListResponse> {
  const { data } = await apiClient.get<ProductSalesListResponse>('/reports/sales/products', { params })
  return data
}

export async function fetchProductSalesCustomers(itemId: string, params: Omit<ProductSalesParams, 'page'>): Promise<ProductSalesCustomerRow[]> {
  const { data } = await apiClient.get<ApiResponse<ProductSalesCustomerRow[]>>(`/reports/sales/products/${itemId}/customers`, { params })
  return data.data
}

export async function exportProductSales(params: Omit<ProductSalesParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/sales/products/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
