import { createCrudApi } from '@/shared/services/crudApi'
import type { Tax, TaxFormValues } from '../types'

const taxCrud = createCrudApi<Tax, TaxFormValues>('/taxes')

export const fetchTaxesPaged = taxCrud.fetchList
export const createTax = taxCrud.create
export const updateTax = taxCrud.update
export const deleteTax = taxCrud.remove
