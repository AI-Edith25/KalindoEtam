import type { GoodsReceiptFilterValues } from '../types'

export const emptyGoodsReceiptFilters: GoodsReceiptFilterValues = { status: null, source: null, dateFrom: '', dateTo: '' }

export function hasActiveGoodsReceiptFilters(filters: GoodsReceiptFilterValues): boolean {
  return filters.status !== null || filters.source !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
