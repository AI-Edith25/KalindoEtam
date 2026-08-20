import type { JournalEntryFilterValues } from '../types'

export const emptyJournalEntryFilters: JournalEntryFilterValues = {
  status: null,
  referenceType: null,
  accountId: null,
  branchId: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveJournalEntryFilters(filters: JournalEntryFilterValues): boolean {
  return (
    filters.status !== null ||
    filters.referenceType !== null ||
    filters.accountId !== null ||
    filters.branchId !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== ''
  )
}
