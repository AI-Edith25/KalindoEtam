import type { JournalListFilterValues } from '../types'

export const emptyJournalListFilters: JournalListFilterValues = {
  branchId: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveJournalListFilters(filters: JournalListFilterValues): boolean {
  return filters.branchId !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
