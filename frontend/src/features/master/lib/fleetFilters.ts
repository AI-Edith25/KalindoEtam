import type { Fleet } from '../types'

export interface FleetFilterValues {
  isActive: boolean | null
}

export const emptyFleetFilters: FleetFilterValues = { isActive: null }

/** Client-side, page-local — same constraint and seam as applySalesPersonFilters. */
export function applyFleetFilters(items: Fleet[], search: string, filters: FleetFilterValues): Fleet[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.isActive !== null && item.is_active !== filters.isActive) return false

    if (!query) return true

    return item.plat_number.toLowerCase().includes(query) || (item.default_driver_name?.toLowerCase().includes(query) ?? false)
  })
}
