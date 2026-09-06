import type { OpeningStockFilterValues } from '../types'

export const emptyOpeningStockFilters: OpeningStockFilterValues = {
  warehouse_id: '',
  status: null,
  dateFrom: '',
  dateTo: '',
  importBatchId: '',
}

export function hasActiveOpeningStockFilters(filters: OpeningStockFilterValues): boolean {
  return (
    filters.warehouse_id !== '' ||
    filters.status !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== '' ||
    filters.importBatchId !== ''
  )
}
