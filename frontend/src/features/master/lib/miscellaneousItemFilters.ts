import type { MiscellaneousItem } from '../types'

/** Nothing filterable beyond Search (misc code/description) — same reasoning as uomFilters.ts, no FiltersBar for this entity. */
export type MiscellaneousItemFilterValues = Record<string, never>

export const emptyMiscellaneousItemFilters: MiscellaneousItemFilterValues = {}

export function applyMiscellaneousItemFilters(items: MiscellaneousItem[], search: string): MiscellaneousItem[] {
  const query = search.trim().toLowerCase()

  if (!query) return items

  return items.filter(
    (item) => item.misc_code.toLowerCase().includes(query) || item.description.toLowerCase().includes(query),
  )
}
