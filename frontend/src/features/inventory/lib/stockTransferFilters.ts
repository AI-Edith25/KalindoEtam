import type { StockTransferFilterValues } from '../types'

export const emptyStockTransferFilters: StockTransferFilterValues = { status: null, warehouse_id: '', dateFrom: '', dateTo: '' }

export function hasActiveStockTransferFilters(filters: StockTransferFilterValues): boolean {
  return filters.status !== null || filters.warehouse_id !== '' || filters.dateFrom !== '' || filters.dateTo !== ''
}
