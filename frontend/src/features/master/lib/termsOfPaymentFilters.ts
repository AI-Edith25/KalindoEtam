import type { TermsOfPayment } from '../types'

/** Nothing filterable beyond Search (code/name) — no FiltersBar for this entity. Same reasoning as uomFilters.ts. */
export type TermsOfPaymentFilterValues = Record<string, never>

export const emptyTermsOfPaymentFilters: TermsOfPaymentFilterValues = {}

export function applyTermsOfPaymentFilters(items: TermsOfPayment[], search: string): TermsOfPayment[] {
  const query = search.trim().toLowerCase()

  if (!query) return items

  return items.filter((item) => item.code.toLowerCase().includes(query) || item.name.toLowerCase().includes(query))
}
