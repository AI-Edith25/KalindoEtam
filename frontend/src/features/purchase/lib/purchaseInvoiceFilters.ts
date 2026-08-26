import type { PurchaseInvoiceFilterValues } from '../types'

export const emptyPurchaseInvoiceFilters: PurchaseInvoiceFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActivePurchaseInvoiceFilters(filters: PurchaseInvoiceFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
