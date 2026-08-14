import { createCrudApi } from '@/shared/services/crudApi'
import type { MiscellaneousItem, MiscellaneousItemFormValues } from '../types'

const miscellaneousItemCrud = createCrudApi<MiscellaneousItem, MiscellaneousItemFormValues>('/miscellaneous-items')

export const fetchMiscellaneousItemsPaged = miscellaneousItemCrud.fetchList
export const createMiscellaneousItem = miscellaneousItemCrud.create
export const updateMiscellaneousItem = miscellaneousItemCrud.update
export const deleteMiscellaneousItem = miscellaneousItemCrud.remove
