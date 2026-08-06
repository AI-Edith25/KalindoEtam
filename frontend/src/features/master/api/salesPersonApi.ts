import { createCrudApi } from '@/shared/services/crudApi'
import type { SalesPerson, SalesPersonFormValues } from '../types'

const salesPersonCrud = createCrudApi<SalesPerson, SalesPersonFormValues>('/sales-persons')

export const fetchSalesPersons = salesPersonCrud.fetchList
export const createSalesPerson = salesPersonCrud.create
export const updateSalesPerson = salesPersonCrud.update
export const deleteSalesPerson = salesPersonCrud.remove
