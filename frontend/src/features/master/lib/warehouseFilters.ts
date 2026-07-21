import type { Warehouse, WarehouseType } from '../types'

export interface WarehouseFilterValues {
  warehouseType: WarehouseType | null
}

export const emptyWarehouseFilters: WarehouseFilterValues = { warehouseType: null }

export function hasActiveWarehouseFilters(filters: WarehouseFilterValues): boolean {
  return filters.warehouseType !== null
}

/** Client-side, page-local — same constraint and seam as applyItemFilters (see itemFilters.ts). */
export function applyWarehouseFilters(items: Warehouse[], search: string, filters: WarehouseFilterValues): Warehouse[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.warehouseType && item.warehouse_type !== filters.warehouseType) return false

    if (!query) return true

    return item.code.toLowerCase().includes(query) || item.name.toLowerCase().includes(query)
  })
}
