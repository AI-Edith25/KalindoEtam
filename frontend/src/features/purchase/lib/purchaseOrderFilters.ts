import type { PurchaseOrderFilterValues } from '../types'

export const emptyPurchaseOrderFilters: PurchaseOrderFilterValues = { status: null, source: null, dateFrom: '', dateTo: '' }

export function hasActivePurchaseOrderFilters(filters: PurchaseOrderFilterValues): boolean {
  return filters.status !== null || filters.source !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
