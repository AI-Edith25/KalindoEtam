import { createCrudApi } from '@/shared/services/crudApi'
import type { NamingSeries, NamingSeriesFormValues } from '../types'

const namingSeriesCrud = createCrudApi<NamingSeries, NamingSeriesFormValues>('/naming-series')

export const fetchNamingSeriesPaged = namingSeriesCrud.fetchList
export const fetchNamingSeriesOne = namingSeriesCrud.fetchOne
export const createNamingSeries = namingSeriesCrud.create
export const updateNamingSeries = namingSeriesCrud.update
export const deleteNamingSeries = namingSeriesCrud.remove
