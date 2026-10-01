import type { InvoiceFilterValues } from '../types'

export const emptyInvoiceFilters: InvoiceFilterValues = { status: null, source: null, dateFrom: '', dateTo: '' }

export function hasActiveInvoiceFilters(filters: InvoiceFilterValues): boolean {
  return filters.status !== null || filters.source !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
