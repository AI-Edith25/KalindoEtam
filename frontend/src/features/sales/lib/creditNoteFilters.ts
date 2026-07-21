import type { CreditNoteFilterValues } from '../types'

export const emptyCreditNoteFilters: CreditNoteFilterValues = { status: null, reason: null, dateFrom: '', dateTo: '' }

export function hasActiveCreditNoteFilters(filters: CreditNoteFilterValues): boolean {
  return filters.status !== null || filters.reason !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
