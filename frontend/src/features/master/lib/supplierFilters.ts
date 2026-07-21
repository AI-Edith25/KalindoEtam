import type { Supplier } from '../types'

export interface SupplierFilterValues {
  isActive: boolean | null
}

export const emptySupplierFilters: SupplierFilterValues = { isActive: null }

export function hasActiveSupplierFilters(filters: SupplierFilterValues): boolean {
  return filters.isActive !== null
}

/** Client-side, page-local — same constraint and seam as applyItemFilters (see itemFilters.ts). */
export function applySupplierFilters(items: Supplier[], search: string, filters: SupplierFilterValues): Supplier[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.isActive !== null && item.is_active !== filters.isActive) return false

    if (!query) return true

    return (
      item.supplier_code.toLowerCase().includes(query) ||
      item.supplier_name.toLowerCase().includes(query) ||
      (item.email?.toLowerCase().includes(query) ?? false)
    )
  })
}
