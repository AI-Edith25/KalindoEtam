import type { DeliveryFilterValues } from '../types'

export const emptyDeliveryFilters: DeliveryFilterValues = { status: null, dateFrom: '', dateTo: '' }

export function hasActiveDeliveryFilters(filters: DeliveryFilterValues): boolean {
  return filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}
