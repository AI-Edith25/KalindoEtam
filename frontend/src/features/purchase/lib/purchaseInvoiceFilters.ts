import type { PurchaseInvoiceFilterValues } from '../types'

export const emptyPurchaseInvoiceFilters: PurchaseInvoiceFilterValues = { status: null, source: null, dateFrom: '', dateTo: '' }

export function hasActivePurchaseInvoiceFilters(filters: PurchaseInvoiceFilterValues): boolean {
  return filters.status !== null || filters.source !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
