import { createCrudApi } from '@/shared/services/crudApi'
import type { Warehouse, WarehouseFormValues } from '../types'

const warehouseCrud = createCrudApi<Warehouse, WarehouseFormValues>('/warehouses')

export const fetchWarehouses = warehouseCrud.fetchList
export const createWarehouse = warehouseCrud.create
export const updateWarehouse = warehouseCrud.update
export const deleteWarehouse = warehouseCrud.remove
