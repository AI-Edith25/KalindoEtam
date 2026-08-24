import type { CashBookFilterValues } from '../types'

export const emptyCashBookFilters: CashBookFilterValues = {
  branchId: null,
  status: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveCashBookFilters(filters: CashBookFilterValues): boolean {
  return filters.branchId !== null || filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
