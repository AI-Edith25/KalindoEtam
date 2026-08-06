import type { SalesPerson } from '../types'

export interface SalesPersonFilterValues {
  isActive: boolean | null
}

export const emptySalesPersonFilters: SalesPersonFilterValues = { isActive: null }

export function hasActiveSalesPersonFilters(filters: SalesPersonFilterValues): boolean {
  return filters.isActive !== null
}

/** Client-side, page-local — same constraint and seam as applyCustomerFilters (see customerFilters.ts). */
export function applySalesPersonFilters(items: SalesPerson[], search: string, filters: SalesPersonFilterValues): SalesPerson[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.isActive !== null && item.is_active !== filters.isActive) return false

    if (!query) return true

    return (
      item.code.toLowerCase().includes(query) ||
      item.name.toLowerCase().includes(query) ||
      (item.email?.toLowerCase().includes(query) ?? false)
    )
  })
}
