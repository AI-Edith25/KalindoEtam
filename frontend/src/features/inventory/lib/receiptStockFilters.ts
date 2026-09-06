import type { ReceiptStockFilterValues } from '../types'

export const emptyReceiptStockFilters: ReceiptStockFilterValues = {
  warehouse_id: '',
  status: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveReceiptStockFilters(filters: ReceiptStockFilterValues): boolean {
  return filters.warehouse_id !== '' || filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
