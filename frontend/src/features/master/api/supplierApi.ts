import { createCrudApi } from '@/shared/services/crudApi'
import type { Supplier, SupplierFormValues } from '../types'

const supplierCrud = createCrudApi<Supplier, SupplierFormValues>('/suppliers')

export const fetchSuppliers = supplierCrud.fetchList
export const createSupplier = supplierCrud.create
export const updateSupplier = supplierCrud.update
export const deleteSupplier = supplierCrud.remove
