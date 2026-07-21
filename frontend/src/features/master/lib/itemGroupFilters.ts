import type { ItemGroup } from '../types'

/**
 * Item Group has nothing filterable beyond what Search already covers
 * (name/description) — no FiltersBar for this entity. Kept as an empty
 * object type (not `void`) so it still satisfies useEntityListPage's
 * generic filter-state shape without inventing a meaningless control.
 */
export type ItemGroupFilterValues = Record<string, never>

export const emptyItemGroupFilters: ItemGroupFilterValues = {}

export function applyItemGroupFilters(items: ItemGroup[], search: string): ItemGroup[] {
  const query = search.trim().toLowerCase()

  if (!query) return items

  return items.filter(
    (item) => item.name.toLowerCase().includes(query) || (item.description?.toLowerCase().includes(query) ?? false),
  )
}
