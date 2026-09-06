import type { IssueStockFilterValues } from '../types'

export const emptyIssueStockFilters: IssueStockFilterValues = {
  warehouse_id: '',
  status: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveIssueStockFilters(filters: IssueStockFilterValues): boolean {
  return filters.warehouse_id !== '' || filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
