import type { SalesTarget } from '../types'

export interface SalesTargetFilterValues {
  periodMonth: number | null
  periodYear: number | null
  salesPersonId: string | null
}

export const emptySalesTargetFilters: SalesTargetFilterValues = {
  periodMonth: null,
  periodYear: null,
  salesPersonId: null,
}

export function hasActiveSalesTargetFilters(filters: SalesTargetFilterValues): boolean {
  return filters.periodMonth !== null || filters.periodYear !== null || filters.salesPersonId !== null
}

/** Client-side, page-local — same constraint and seam as applySalesPersonFilters (see salesPersonFilters.ts). */
export function applySalesTargetFilters(items: SalesTarget[], search: string, filters: SalesTargetFilterValues): SalesTarget[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.periodMonth !== null && item.period_month !== filters.periodMonth) return false
    if (filters.periodYear !== null && item.period_year !== filters.periodYear) return false
    if (filters.salesPersonId !== null && item.sales_person_id !== filters.salesPersonId) return false

    if (!query) return true

    return (
      (item.sales_person?.name.toLowerCase().includes(query) ?? false) ||
      (item.sales_person?.code.toLowerCase().includes(query) ?? false) ||
      (item.branch?.name.toLowerCase().includes(query) ?? false)
    )
  })
}
