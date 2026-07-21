import { createCrudApi } from '@/shared/services/crudApi'
import type { ItemGroup, ItemGroupFormValues } from '../types'

const itemGroupCrud = createCrudApi<ItemGroup, ItemGroupFormValues>('/item-groups')

export const fetchItemGroupsPaged = itemGroupCrud.fetchList
export const createItemGroup = itemGroupCrud.create
export const updateItemGroup = itemGroupCrud.update
export const deleteItemGroup = itemGroupCrud.remove
