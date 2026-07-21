import type { AccountType, ChartOfAccount } from '../types'

export interface ChartOfAccountFilterValues {
  accountType: AccountType | null
}

export const emptyChartOfAccountFilters: ChartOfAccountFilterValues = { accountType: null }

export function hasActiveChartOfAccountFilters(filters: ChartOfAccountFilterValues): boolean {
  return filters.accountType !== null
}

/** Client-side, page-local — same constraint and seam as applyWarehouseFilters (see warehouseFilters.ts). */
export function applyChartOfAccountFilters(items: ChartOfAccount[], search: string, filters: ChartOfAccountFilterValues): ChartOfAccount[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.accountType && item.account_type !== filters.accountType) return false

    if (!query) return true

    return item.code.toLowerCase().includes(query) || item.name.toLowerCase().includes(query)
  })
}
