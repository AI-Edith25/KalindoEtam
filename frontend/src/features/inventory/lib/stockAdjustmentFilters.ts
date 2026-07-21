import type { StockAdjustmentFilterValues } from '../types'

export const emptyStockAdjustmentFilters: StockAdjustmentFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActiveStockAdjustmentFilters(filters: StockAdjustmentFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
