import type { Item } from '../types'

export interface ItemFilterValues {
  itemGroupId: string | null
  uomId: string | null
}

export const emptyItemFilters: ItemFilterValues = { itemGroupId: null, uomId: null }

export function hasActiveItemFilters(filters: ItemFilterValues): boolean {
  return filters.itemGroupId !== null || filters.uomId !== null
}

/**
 * Client-side filtering over the currently-loaded page — the item list
 * endpoint doesn't accept filter query params yet (see
 * docs/FRONTEND_ITEM_PAGE.md §5). This function is the single seam: the
 * day the backend adds `IndexItemRequest` filtering, this call is
 * replaced with query params on `fetchItems`, and neither
 * ItemFiltersBar nor ItemListPage's JSX needs to change — only this
 * function's body and where it's invoked.
 */
export function applyItemFilters(items: Item[], search: string, filters: ItemFilterValues): Item[] {
  const query = search.trim().toLowerCase()

  return items.filter((item) => {
    if (filters.itemGroupId && item.item_group_id !== filters.itemGroupId) return false
    if (filters.uomId && item.uom_id !== filters.uomId) return false

    if (!query) return true

    return (
      item.item_code.toLowerCase().includes(query) ||
      item.item_name.toLowerCase().includes(query) ||
      (item.item_group?.name.toLowerCase().includes(query) ?? false)
    )
  })
}
