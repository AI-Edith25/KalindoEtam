import type { SalesOrderFilterValues } from '../types'

export const emptySalesOrderFilters: SalesOrderFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActiveSalesOrderFilters(filters: SalesOrderFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
