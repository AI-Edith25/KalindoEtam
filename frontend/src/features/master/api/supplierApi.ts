import { createCrudApi } from '@/shared/services/crudApi'
import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Supplier, SupplierFormValues } from '../types'

const supplierCrud = createCrudApi<Supplier, SupplierFormValues>('/suppliers')

export const fetchSuppliers = supplierCrud.fetchList
/** Resolves one Supplier by id — used by SearchableSelect's async dropdowns to restore a pre-set filter's label. */
export const fetchSupplier = supplierCrud.fetchOne
export const createSupplier = supplierCrud.create
export const updateSupplier = supplierCrud.update
export const deleteSupplier = supplierCrud.remove

export const fetchTrashedSuppliers = async (page: number): Promise<ApiListResponse<Supplier>> => {
  const { data } = await apiClient.get<ApiListResponse<Supplier>>('/suppliers/trashed', { params: { page } })
  return data
}

export const restoreSupplier = async (id: string): Promise<Supplier> => {
  const { data } = await apiClient.post<ApiResponse<Supplier>>(`/suppliers/${id}/restore`)
  return data.data
}
