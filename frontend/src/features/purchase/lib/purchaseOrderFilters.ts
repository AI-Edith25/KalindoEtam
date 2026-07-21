import type { PurchaseOrderFilterValues } from '../types'

export const emptyPurchaseOrderFilters: PurchaseOrderFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActivePurchaseOrderFilters(filters: PurchaseOrderFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
