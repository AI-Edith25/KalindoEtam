import { createCrudApi } from '@/shared/services/crudApi'
import type { Supplier, SupplierFormValues } from '../types'

const supplierCrud = createCrudApi<Supplier, SupplierFormValues>('/suppliers')

export const fetchSuppliers = supplierCrud.fetchList
/** Resolves one Supplier by id — used by SearchableSelect's async dropdowns to restore a pre-set filter's label. */
export const fetchSupplier = supplierCrud.fetchOne
export const createSupplier = supplierCrud.create
export const updateSupplier = supplierCrud.update
export const deleteSupplier = supplierCrud.remove
