import { createCrudApi } from '@/shared/services/crudApi'
import type { Customer, CustomerFormValues } from '../types'

const customerCrud = createCrudApi<Customer, CustomerFormValues>('/customers')

export const fetchCustomers = customerCrud.fetchList
export const createCustomer = customerCrud.create
export const updateCustomer = customerCrud.update
export const deleteCustomer = customerCrud.remove
