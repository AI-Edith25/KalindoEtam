import { apiClient } from '@/shared/services/apiClient'
import { createCrudApi } from '@/shared/services/crudApi'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Customer, CustomerFormValues } from '../types'
import type { CustomerFilterValues } from '../lib/customerFilters'

const customerCrud = createCrudApi<Customer, CustomerFormValues>('/customers')

/** Search/status filter go to the server (see useEntityListPage's `serverSearch`) — 2k+ customers span 11 pages of 200, too many to filter client-side. */
export async function fetchCustomers(page: number, search?: string, filters?: CustomerFilterValues): Promise<ApiListResponse<Customer>> {
  const { data } = await apiClient.get<ApiListResponse<Customer>>('/customers', {
    params: { page, search: search || undefined, is_active: filters?.isActive ?? undefined },
  })
  return data
}
/** Resolves one Customer by id — used by SearchableSelect's async dropdowns to restore a pre-set filter's label. */
export const fetchCustomer = customerCrud.fetchOne
export const createCustomer = customerCrud.create
export const updateCustomer = customerCrud.update
export const deleteCustomer = customerCrud.remove

/** Preview of the code the New Customer form will get on save — see CustomerController::nextCode(). Cosmetic only, can go stale if another user creates one first; the server generates the real value fresh on submit regardless. */
export async function fetchNextCustomerCode(category: 'C' | 'PK' | 'PL' = 'C'): Promise<string> {
  const { data } = await apiClient.get<ApiResponse<{ customer_code: string }>>('/customers/next-code', { params: { category } })
  return data.data.customer_code
}

/** Maintenance > Customers "Export" button — server-side search/status filter, all matching rows (not just the loaded page). */
export async function exportCustomers(params: { search?: string; is_active?: boolean }): Promise<Blob> {
  const { data } = await apiClient.get<Blob>('/customers/export', { params, responseType: 'blob' })
  return data
}
