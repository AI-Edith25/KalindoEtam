import { useState } from 'react'

export type PageSize = 50 | 100 | 250 | 500 | 1000 | 'all'

export const PAGE_SIZE_OPTIONS: PageSize[] = [50, 100, 250, 500, 1000, 'all']

const STORAGE_PREFIX = 'pageSize:'

function readStored(key: string, fallback: PageSize): PageSize {
  try {
    const raw = localStorage.getItem(STORAGE_PREFIX + key)
    if (raw === 'all') return 'all'
    const n = Number(raw)
    return (PAGE_SIZE_OPTIONS as (number | string)[]).includes(n) ? (n as PageSize) : fallback
  } catch {
    return fallback
  }
}

/** Persists the chosen "Show N entries" page size per listing page (by `key`) across reloads — falls back to session-only if localStorage is unavailable (private window, blocked site data). */
export function usePageSize(key: string, fallback: PageSize = 50) {
  const [pageSize, setPageSizeState] = useState<PageSize>(() => readStored(key, fallback))

  const setPageSize = (next: PageSize) => {
    setPageSizeState(next)
    try {
      localStorage.setItem(STORAGE_PREFIX + key, String(next))
    } catch {
      // ponytail: best-effort persistence only, ignore quota/privacy errors
    }
  }

  return [pageSize, setPageSize] as const
}

/** The per_page value actually sent to the API — 'all' maps to a high but bounded ceiling (every IndexXRequest's own per_page rule now allows up to this), never an actually-unbounded request. */
export function resolvePerPage(pageSize: PageSize): number {
  return pageSize === 'all' ? 100000 : pageSize
}
