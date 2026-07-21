import type { StockLedgerFilterValues } from '../types'

export const emptyStockLedgerFilters: StockLedgerFilterValues = {
  warehouse_id: '',
  item_id: '',
  voucher_type: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveStockLedgerFilters(filters: StockLedgerFilterValues): boolean {
  return (
    filters.warehouse_id !== '' ||
    filters.item_id !== '' ||
    filters.voucher_type !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== ''
  )
}
