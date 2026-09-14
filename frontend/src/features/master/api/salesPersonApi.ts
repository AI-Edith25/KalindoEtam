import { apiClient } from '@/shared/services/apiClient'
import { createCrudApi } from '@/shared/services/crudApi'
import type { ApiResponse } from '@/shared/types/api'
import type { SalesPerson, SalesPersonFormValues } from '../types'

const salesPersonCrud = createCrudApi<SalesPerson, SalesPersonFormValues>('/sales-persons')

export const fetchSalesPersons = salesPersonCrud.fetchList
export const createSalesPerson = salesPersonCrud.create
export const updateSalesPerson = salesPersonCrud.update
export const deleteSalesPerson = salesPersonCrud.remove

/** Preview of the code the New Sales Person form will get on save — see SalesPersonController::nextCode(). Cosmetic only, can go stale if another user creates one first; the server generates the real value fresh on submit regardless. */
export async function fetchNextSalesPersonCode(): Promise<string> {
  const { data } = await apiClient.get<ApiResponse<{ code: string }>>('/sales-persons/next-code')
  return data.data.code
}
