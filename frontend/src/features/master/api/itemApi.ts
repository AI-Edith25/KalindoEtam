import { apiClient } from '@/shared/services/apiClient'
import { createCrudApi } from '@/shared/services/crudApi'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { Item, ItemFormValues } from '../types'

const itemCrud = createCrudApi<Item, ItemFormValues>('/items')

export const fetchItems = itemCrud.fetchList
export const fetchItem = itemCrud.fetchOne
export const createItem = itemCrud.create
export const updateItem = itemCrud.update
export const deleteItem = itemCrud.remove

export interface ItemPriceMatrixParams {
  page: number
  per_page?: number
  search?: string
  item_group_id?: string
  warehouse_id?: string
}

/** Dedicated paginated fetch for the Item Prices matrix — doesn't fit createCrudApi's fetchList(page) signature since it needs search/filter/warehouse params too. */
export async function fetchItemsForPriceMatrix(params: ItemPriceMatrixParams): Promise<ApiListResponse<Item>> {
  const { data } = await apiClient.get<ApiListResponse<Item>>('/items', { params })
  return data
}

/** Partial PUT — `updateItem` requires the full ItemFormValues shape, but the Item Prices matrix only ever edits this one field inline. UpdateItemRequest already validates it standalone via 'sometimes'. */
export async function updateItemStandardRate(id: string, standardRate: number): Promise<Item> {
  const { data } = await apiClient.put<ApiResponse<Item>>(`/items/${id}`, { standard_rate: standardRate })
  return data.data
}
