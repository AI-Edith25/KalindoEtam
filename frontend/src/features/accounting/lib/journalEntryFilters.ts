import type { JournalEntryFilterValues } from '../types'

export const emptyJournalEntryFilters: JournalEntryFilterValues = {
  status: null,
  referenceType: null,
  accountId: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveJournalEntryFilters(filters: JournalEntryFilterValues): boolean {
  return (
    filters.status !== null ||
    filters.referenceType !== null ||
    filters.accountId !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== ''
  )
}
