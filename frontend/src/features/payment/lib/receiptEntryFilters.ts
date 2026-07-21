import type { ReceiptEntryFilterValues } from '../types'

export const emptyReceiptEntryFilters: ReceiptEntryFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActiveReceiptEntryFilters(filters: ReceiptEntryFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
