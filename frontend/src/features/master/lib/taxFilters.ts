import type { Tax } from '../types'

/** Nothing filterable beyond Search (code/name) — same minimal shape ItemGroupFilterValues already established. */
export type TaxFilterValues = Record<string, never>

export const emptyTaxFilters: TaxFilterValues = {}

export function applyTaxFilters(items: Tax[], search: string): Tax[] {
  const query = search.trim().toLowerCase()

  if (!query) return items

  return items.filter((tax) => tax.code.toLowerCase().includes(query) || tax.name.toLowerCase().includes(query))
}
