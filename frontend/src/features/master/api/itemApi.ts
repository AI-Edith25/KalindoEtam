import { createCrudApi } from '@/shared/services/crudApi'
import type { Item, ItemFormValues } from '../types'

const itemCrud = createCrudApi<Item, ItemFormValues>('/items')

export const fetchItems = itemCrud.fetchList
export const fetchItem = itemCrud.fetchOne
export const createItem = itemCrud.create
export const updateItem = itemCrud.update
export const deleteItem = itemCrud.remove
