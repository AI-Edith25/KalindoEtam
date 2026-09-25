import { apiClient } from '@/shared/services/apiClient'
import { createCrudApi } from '@/shared/services/crudApi'
import type { ApiListResponse } from '@/shared/types/api'
import type { Supplier, SupplierFormValues } from '../types'

const supplierCrud = createCrudApi<Supplier, SupplierFormValues>('/suppliers')

/**
 * createCrudApi's fetchList(page) never sends per_page, so the backend's
 * default (200) split the list across pages — and SupplierListPage's search
 * box only filters the current page client-side (see docs/ERP_DESIGN_SYSTEM.md
 * §4), so a supplier past page 1 looked "missing" once the list passed 200
 * rows. One page of 1000 keeps every supplier searchable client-side again.
 */
export const fetchSuppliers = async (page: number): Promise<ApiListResponse<Supplier>> => {
  const { data } = await apiClient.get<ApiListResponse<Supplier>>('/suppliers', { params: { page, per_page: 1000 } })
  return data
}
/** Resolves one Supplier by id — used by SearchableSelect's async dropdowns to restore a pre-set filter's label. */
export const fetchSupplier = supplierCrud.fetchOne
export const createSupplier = supplierCrud.create
export const updateSupplier = supplierCrud.update
export const deleteSupplier = supplierCrud.remove
