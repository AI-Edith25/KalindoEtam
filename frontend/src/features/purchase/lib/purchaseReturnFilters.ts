import type { PurchaseReturnFilterValues } from '../types'

export const emptyPurchaseReturnFilters: PurchaseReturnFilterValues = { status: null, reason: null, dateFrom: '', dateTo: '' }

export function hasActivePurchaseReturnFilters(filters: PurchaseReturnFilterValues): boolean {
  return filters.status !== null || filters.reason !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
