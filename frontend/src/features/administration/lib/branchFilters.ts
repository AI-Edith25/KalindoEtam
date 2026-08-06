import type { Branch } from '@/features/master/types'

export interface BranchFilterValues {
  isActive: boolean | null
}

export const emptyBranchFilters: BranchFilterValues = { isActive: null }

export function hasActiveBranchFilters(filters: BranchFilterValues): boolean {
  return filters.isActive !== null
}

/** Client-side, page-local — same constraint and seam as applyCustomerFilters (see master/lib/customerFilters.ts). */
export function applyBranchFilters(items: Branch[], search: string, filters: BranchFilterValues): Branch[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.isActive !== null && item.is_active !== filters.isActive) return false

    if (!query) return true

    return item.code.toLowerCase().includes(query) || item.name.toLowerCase().includes(query)
  })
}
