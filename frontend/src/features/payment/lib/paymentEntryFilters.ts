import type { PaymentEntryFilterValues } from '../types'

export const emptyPaymentEntryFilters: PaymentEntryFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActivePaymentEntryFilters(filters: PaymentEntryFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
