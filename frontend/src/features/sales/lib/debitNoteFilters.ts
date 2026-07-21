import type { DebitNoteFilterValues } from '../types'

export const emptyDebitNoteFilters: DebitNoteFilterValues = { status: null, reason: null, dateFrom: '', dateTo: '' }

export function hasActiveDebitNoteFilters(filters: DebitNoteFilterValues): boolean {
  return filters.status !== null || filters.reason !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
