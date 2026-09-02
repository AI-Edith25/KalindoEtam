import { createCrudApi } from '@/shared/services/crudApi'
import type { PriceZone, PriceZoneFormValues } from '../types'

const priceZoneCrud = createCrudApi<PriceZone, PriceZoneFormValues>('/price-zones')

export const fetchPriceZonesPaged = priceZoneCrud.fetchList
export const createPriceZone = priceZoneCrud.create
export const updatePriceZone = priceZoneCrud.update
export const deletePriceZone = priceZoneCrud.remove
