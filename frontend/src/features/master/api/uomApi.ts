import { createCrudApi } from '@/shared/services/crudApi'
import type { Uom, UomFormValues } from '../types'

const uomCrud = createCrudApi<Uom, UomFormValues>('/uoms')

export const fetchUomsPaged = uomCrud.fetchList
export const createUom = uomCrud.create
export const updateUom = uomCrud.update
export const deleteUom = uomCrud.remove
