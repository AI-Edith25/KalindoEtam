import type { Uom } from '../types'

/** UOM has nothing filterable beyond Search (name/symbol) — no FiltersBar for this entity. See itemGroupFilters.ts for the same reasoning. */
export type UomFilterValues = Record<string, never>

export const emptyUomFilters: UomFilterValues = {}

export function applyUomFilters(items: Uom[], search: string): Uom[] {
  const query = search.trim().toLowerCase()

  if (!query) return items

  return items.filter(
    (item) => item.name.toLowerCase().includes(query) || (item.symbol?.toLowerCase().includes(query) ?? false),
  )
}
