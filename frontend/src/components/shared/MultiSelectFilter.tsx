import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronsUpDown, Loader2, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

export interface MultiSelectOption {
  value: string
  label: string
}

interface MultiSelectFilterProps {
  /** Sync mode: full option list already fetched — filtered client-side as you type. */
  options?: MultiSelectOption[]
  /**
   * Async mode: called (debounced ~250ms) with the current query as the user types, for
   * datasets too large to load upfront (e.g. Customer). Mutually exclusive with `options` —
   * when present, the search box hits the server instead of filtering `options`, same contract
   * as SearchableSelect.tsx's own `loadOptions`.
   */
  loadOptions?: (query: string) => Promise<MultiSelectOption[]>
  /** Async mode only: the label to show on the trigger when exactly one id is selected — the
   *  caller resolves it (that one id may not be in the last search result at all). Ignored in
   *  sync mode, where the label comes straight from `options`. */
  resolvedSingleLabel?: string
  /** Selected option ids — never labels, so callers store/URL-sync stable ids. */
  value: string[]
  onChange: (next: string[]) => void
  loading?: boolean
  /** Trigger label when nothing is selected, e.g. "All sales persons". */
  placeholder?: string
  /** Word used in the "N ... dipilih" chip label once 2+ are selected, e.g. "salesman" -> "3 salesman dipilih". */
  itemLabel?: string
  className?: string
  'aria-label'?: string
}

/**
 * Reusable multi-select filter: search box, checkbox list, "Pilih semua" (scoped to the current
 * search — see selectAllFiltered) / "Hapus pilihan", selected options pinned to the top (sync
 * mode only), and a small x on the trigger for a one-click reset. Modeled on SearchableSelect.tsx's
 * own skeleton (DropdownMenu + Input + plain buttons, no new popover/command dependency) — the one
 * behavioral difference is that picking a row here never closes the menu, since picking several
 * is the point.
 *
 * Every row gets a tooltip with its full label (`Tooltip`, not a conditional-on-overflow check —
 * the trigger's own width already fits the longest label via `ch`, so this only ever fires for a
 * font-metric edge case `ch` didn't account for; always mounting it is simpler than a measured
 * overflow check for that rare case).
 */
export function MultiSelectFilter({
  options,
  loadOptions,
  resolvedSingleLabel,
  value,
  onChange,
  loading,
  placeholder = 'All',
  itemLabel = 'item',
  className,
  ...rest
}: MultiSelectFilterProps) {
  const isAsync = !!loadOptions
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [highlighted, setHighlighted] = useState(0)
  const [asyncOptions, setAsyncOptions] = useState<MultiSelectOption[]>([])
  const [searching, setSearching] = useState(false)
  const requestId = useRef(0)

  const selectedSet = useMemo(() => new Set(value), [value])

  useEffect(() => {
    if (!isAsync || !open) return
    const id = ++requestId.current
    setSearching(true)
    const handle = setTimeout(() => {
      loadOptions!(query.trim())
        .then((result) => {
          if (requestId.current === id) setAsyncOptions(result)
        })
        .finally(() => {
          if (requestId.current === id) setSearching(false)
        })
    }, 250)

    return () => clearTimeout(handle)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, open, isAsync])

  // Selected-first (A.5) — stable otherwise (source order). Sync mode only: the full option set
  // isn't loaded client-side in async mode, so pinning selected rows above the search result
  // isn't meaningful there (ponytail: skipped — add if this turns out to matter).
  const ordered = useMemo(() => {
    if (isAsync) return options ?? []
    const selected = (options ?? []).filter((o) => selectedSet.has(o.value))
    const unselected = (options ?? []).filter((o) => !selectedSet.has(o.value))
    return [...selected, ...unselected]
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [options, value, isAsync])

  const filtered = useMemo(() => {
    if (isAsync) return asyncOptions
    const q = query.trim().toLowerCase()
    return q ? ordered.filter((o) => o.label.toLowerCase().includes(q)) : ordered
  }, [ordered, query, isAsync, asyncOptions])

  useEffect(() => setHighlighted(0), [filtered.length, query])

  const toggle = (id: string) => onChange(selectedSet.has(id) ? value.filter((v) => v !== id) : [...value, id])

  // "kalau sedang mencari, hanya memilih hasil pencarian" — unions the currently-filtered rows
  // into the existing selection rather than replacing it, so a second search+"Pilih semua" can
  // build up a selection incrementally.
  const selectAllFiltered = () => onChange([...new Set([...value, ...filtered.map((o) => o.value)])])
  const clearAll = () => onChange([])

  const handleKeyDown = (event: React.KeyboardEvent) => {
    event.stopPropagation()
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setHighlighted((h) => Math.min(h + 1, filtered.length - 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlighted((h) => Math.max(h - 1, 0))
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      const option = filtered[highlighted]
      if (option) toggle(option.value)
    } else if (event.key === 'Escape') {
      event.preventDefault()
      setOpen(false)
    }
  }

  const selectedOptions = isAsync ? [] : (options ?? []).filter((o) => selectedSet.has(o.value))
  const label =
    value.length === 0
      ? placeholder
      : value.length === 1
        ? (isAsync ? resolvedSingleLabel ?? placeholder : selectedOptions[0]?.label ?? placeholder)
        : `${value.length} ${itemLabel} dipilih`

  // Popover width: 260px floor, or the longest label + room for the checkbox/padding, whichever
  // is wider — `ch` is font-metric-approximate but cheap (no measurement pass needed for a filter
  // dropdown; the per-row tooltip covers the rare miss). Async mode has no upfront option list to
  // measure, so it just keeps the 260px floor.
  const widthCh = isAsync ? 0 : Math.max(...(options ?? []).map((o) => o.label.length), 0) + 6

  return (
    <DropdownMenu
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) setQuery('')
      }}
    >
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-label={rest['aria-label']}
          disabled={loading}
          className={cn('w-full justify-between font-normal', value.length === 0 && 'text-muted-foreground', className)}
        >
          <span className="truncate">{loading ? 'Loading…' : label}</span>
          <span className="flex shrink-0 items-center gap-1">
            {value.length > 0 && (
              <X
                className="size-3.5 opacity-60 hover:opacity-100"
                onClick={(event) => {
                  event.stopPropagation()
                  clearAll()
                }}
              />
            )}
            <ChevronsUpDown className="size-4 opacity-50" />
          </span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        className="p-0 duration-0"
        style={{ width: isAsync ? '260px' : `max(260px, ${widthCh}ch)` }}
        align="start"
        side="bottom"
        avoidCollisions={false}
      >
        <div className="p-1.5">
          <Input autoFocus value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={handleKeyDown} placeholder="Cari…" className="h-8" aria-label="Cari" />
        </div>
        <div className="flex items-center gap-1 border-b px-1.5 pb-1.5">
          <button type="button" className="rounded-sm px-2 py-1 text-xs text-primary hover:bg-accent" onClick={selectAllFiltered}>
            Pilih semua
          </button>
          <button type="button" className="rounded-sm px-2 py-1 text-xs text-muted-foreground hover:bg-accent" onClick={clearAll}>
            Hapus pilihan
          </button>
        </div>
        <TooltipProvider>
          <div role="listbox" aria-multiselectable className="max-h-64 overflow-y-auto p-1">
            {isAsync && searching && filtered.length === 0 && (
              <p className="flex items-center gap-2 px-2 py-1.5 text-sm text-muted-foreground">
                <Loader2 className="size-3.5 animate-spin" /> Memuat…
              </p>
            )}
            {!(isAsync && searching) && filtered.length === 0 && (
              <p className="px-2 py-1.5 text-sm text-muted-foreground">Tidak ada data yang cocok</p>
            )}
            {filtered.map((option, index) => {
              const checked = selectedSet.has(option.value)
              return (
                <Tooltip key={option.value}>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      role="option"
                      aria-selected={checked}
                      className={cn(
                        'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent',
                        index === highlighted && 'bg-accent',
                      )}
                      onMouseEnter={() => setHighlighted(index)}
                      onClick={() => toggle(option.value)}
                    >
                      <Checkbox checked={checked} className="pointer-events-none" />
                      <span className="truncate">{option.label}</span>
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="right">{option.label}</TooltipContent>
                </Tooltip>
              )
            })}
          </div>
        </TooltipProvider>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
