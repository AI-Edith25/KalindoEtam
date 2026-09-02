import type { PriceZone } from '../types'

/** Price Zone has nothing filterable beyond Search (name/description) — same shape as Item Group. */
export type PriceZoneFilterValues = Record<string, never>

export const emptyPriceZoneFilters: PriceZoneFilterValues = {}

export function applyPriceZoneFilters(items: PriceZone[], search: string): PriceZone[] {
  const query = search.trim().toLowerCase()

  if (!query) return items

  return items.filter(
    (item) => item.name.toLowerCase().includes(query) || (item.description?.toLowerCase().includes(query) ?? false),
  )
}
