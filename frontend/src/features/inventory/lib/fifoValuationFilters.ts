import type { FifoValuationFilterValues } from '../types'

export const emptyFifoValuationFilters: FifoValuationFilterValues = {
  warehouse_id: '',
  item_group_id: '',
  item_id: '',
  dateFrom: '',
  dateTo: '',
  hideExhausted: false,
}

export function hasActiveFifoValuationFilters(filters: FifoValuationFilterValues): boolean {
  return (
    filters.warehouse_id !== '' ||
    filters.item_group_id !== '' ||
    filters.item_id !== '' ||
    filters.dateFrom !== '' ||
    filters.dateTo !== '' ||
    filters.hideExhausted
  )
}
