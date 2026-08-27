import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * Syncs a filter object to the URL query string — read on mount/navigation,
 * written on every change, so filters survive refresh/back-navigation and
 * are shareable/bookmarkable (none of the Sales list pages had this before;
 * they used plain useState). `defaults` decides each key's shape: an array
 * default reads/writes repeated params (`status=a&status=b`), anything else
 * reads/writes a single param. Empty/undefined values are omitted from the
 * URL entirely rather than written as empty strings.
 */
export function useUrlFilters<T extends Record<keyof T, string | string[]>>(
  defaults: T,
): [T, (next: Partial<T>) => void, () => void] {
  const [searchParams, setSearchParams] = useSearchParams()

  const filters = useMemo(() => {
    const result = { ...defaults }
    for (const key of Object.keys(defaults)) {
      const values = searchParams.getAll(key)
      if (values.length === 0) continue
      result[key as keyof T] = (Array.isArray(defaults[key as keyof T]) ? values : values[0]) as T[keyof T]
    }
    return result
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams])

  const setFilters = useCallback(
    (next: Partial<T>) => {
      setSearchParams((prev) => {
        const params = new URLSearchParams(prev)
        for (const [key, value] of Object.entries(next)) {
          params.delete(key)
          if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) continue
          if (Array.isArray(value)) {
            value.forEach((v) => params.append(key, v))
          } else {
            params.set(key, String(value))
          }
        }
        return params
      })
    },
    [setSearchParams],
  )

  const reset = useCallback(() => setSearchParams(new URLSearchParams()), [setSearchParams])

  return [filters, setFilters, reset]
}
