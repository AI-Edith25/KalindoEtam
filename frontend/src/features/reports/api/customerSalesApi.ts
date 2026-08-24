import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { CustomerSalesDocuments, CustomerSalesKpis, CustomerSalesRow, SalesAchievementRow } from '../types'

export interface CustomerSalesParams {
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
  sort?: 'amount' | 'qty' | 'customer_name' | 'transaction_count'
  sort_dir?: 'asc' | 'desc'
}

export interface CustomerSalesListResponse extends ApiListResponse<CustomerSalesRow> {
  meta: PaginationMeta & { kpis: CustomerSalesKpis }
}

export async function fetchCustomerSales(params: CustomerSalesParams): Promise<CustomerSalesListResponse> {
  const { data } = await apiClient.get<CustomerSalesListResponse>('/reports/sales/customers', { params })
  return data
}

export async function fetchCustomerSalesDocuments(customerId: string, params: Omit<CustomerSalesParams, 'page'>): Promise<CustomerSalesDocuments> {
  const { data } = await apiClient.get<ApiResponse<CustomerSalesDocuments>>(`/reports/sales/customers/${customerId}/documents`, { params })
  return data.data
}

export async function fetchSalesAchievement(params: Omit<CustomerSalesParams, 'page'>): Promise<SalesAchievementRow[]> {
  const { data } = await apiClient.get<ApiResponse<SalesAchievementRow[]>>('/reports/sales/achievement', { params })
  return data.data
}

export async function exportCustomerSales(params: Omit<CustomerSalesParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/sales/customers/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
