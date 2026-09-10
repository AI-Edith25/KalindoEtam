import { createCrudApi } from '@/shared/services/crudApi'
import type { Customer, CustomerFormValues } from '../types'

const customerCrud = createCrudApi<Customer, CustomerFormValues>('/customers')

export const fetchCustomers = customerCrud.fetchList
/** Resolves one Customer by id — used by SearchableSelect's async dropdowns to restore a pre-set filter's label. */
export const fetchCustomer = customerCrud.fetchOne
export const createCustomer = customerCrud.create
export const updateCustomer = customerCrud.update
export const deleteCustomer = customerCrud.remove
