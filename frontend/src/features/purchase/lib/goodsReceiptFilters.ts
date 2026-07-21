import type { GoodsReceiptFilterValues } from '../types'

export const emptyGoodsReceiptFilters: GoodsReceiptFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActiveGoodsReceiptFilters(filters: GoodsReceiptFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
