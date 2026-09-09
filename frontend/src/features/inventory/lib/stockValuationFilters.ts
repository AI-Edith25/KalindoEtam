import type { StockValuationFilterValues } from '../types'

function toIsoDate(date: Date): string {
  return date.toISOString().slice(0, 10)
}

/** Defaults to month-to-date — a period report needs some range to be meaningful, unlike Ledger's optional dates. */
function defaultFilters(): StockValuationFilterValues {
  const today = new Date()
  const monthStart = new Date(today.getFullYear(), today.getMonth(), 1)

  return {
    warehouse_id: '',
    item_group_id: '',
    item_id: '',
    dateFrom: toIsoDate(monthStart),
    dateTo: toIsoDate(today),
  }
}

export const emptyStockValuationFilters: StockValuationFilterValues = defaultFilters()

export function hasActiveStockValuationFilters(filters: StockValuationFilterValues): boolean {
  return filters.warehouse_id !== '' || filters.item_group_id !== '' || filters.item_id !== ''
}
