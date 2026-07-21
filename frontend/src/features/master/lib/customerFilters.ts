import type { Customer } from '../types'

export interface CustomerFilterValues {
  isActive: boolean | null
}

export const emptyCustomerFilters: CustomerFilterValues = { isActive: null }

export function hasActiveCustomerFilters(filters: CustomerFilterValues): boolean {
  return filters.isActive !== null
}

/** Client-side, page-local — same constraint and seam as applyItemFilters (see itemFilters.ts). */
export function applyCustomerFilters(items: Customer[], search: string, filters: CustomerFilterValues): Customer[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.isActive !== null && item.is_active !== filters.isActive) return false

    if (!query) return true

    return (
      item.customer_code.toLowerCase().includes(query) ||
      item.customer_name.toLowerCase().includes(query) ||
      (item.email?.toLowerCase().includes(query) ?? false)
    )
  })
}
