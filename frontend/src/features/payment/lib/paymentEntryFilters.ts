import type { PaymentEntryFilterValues } from '../types'

export const emptyPaymentEntryFilters: PaymentEntryFilterValues = { status: null, source: null, dateFrom: '', dateTo: '', unallocatedOnly: false }

export function hasActivePaymentEntryFilters(filters: PaymentEntryFilterValues): boolean {
  return filters.status !== null || filters.source !== null || filters.dateFrom !== '' || filters.dateTo !== '' || filters.unallocatedOnly
}
