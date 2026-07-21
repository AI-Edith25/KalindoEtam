import type { StockBalanceFilterValues } from '../types'

export const emptyStockBalanceFilters: StockBalanceFilterValues = { warehouse_id: '', item_group_id: '', item_id: '' }

export function hasActiveStockBalanceFilters(filters: StockBalanceFilterValues): boolean {
  return filters.warehouse_id !== '' || filters.item_group_id !== '' || filters.item_id !== ''
}
