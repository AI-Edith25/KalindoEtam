import { apiClient } from '@/shared/services/apiClient'
import { createCrudApi } from '@/shared/services/crudApi'
import type { ApiResponse } from '@/shared/types/api'
import type { Customer, CustomerFormValues } from '../types'

const customerCrud = createCrudApi<Customer, CustomerFormValues>('/customers')

export const fetchCustomers = customerCrud.fetchList
/** Resolves one Customer by id — used by SearchableSelect's async dropdowns to restore a pre-set filter's label. */
export const fetchCustomer = customerCrud.fetchOne
export const createCustomer = customerCrud.create
export const updateCustomer = customerCrud.update
export const deleteCustomer = customerCrud.remove

/** Preview of the code the New Customer form will get on save — see CustomerController::nextCode(). Cosmetic only, can go stale if another user creates one first; the server generates the real value fresh on submit regardless. */
export async function fetchNextCustomerCode(): Promise<string> {
  const { data } = await apiClient.get<ApiResponse<{ customer_code: string }>>('/customers/next-code')
  return data.data.customer_code
}
