import { useEffect, useMemo, useRef, useState } from 'react'
import { useQueries } from '@tanstack/react-query'
import { ChevronsUpDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'
import { fetchItem } from '@/features/master/api/itemApi'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'

function itemLabel(item: { item_code: string; item_name: string }) {
  return `${item.item_code} — ${item.item_name}`
}

interface ItemMultiFilterProps {
  /** Selected item ids — never labels, so callers store/send stable ids. */
  value: string[]
  onChange: (next: string[]) => void
  className?: string
}

/**
 * Multiple-item picker for the Inventory Stock report filters (Balance/Ledger/Valuation), as one
 * checklist dropdown — modeled on MultiSelectFilter's checkbox-list UX, but with an async
 * server-side search instead of a pre-loaded options array, since the Item master can run into
 * the thousands (same reason SearchableSelect's Item field is async everywhere else). Selected
 * rows are pinned to the top (checked) even once a search scrolls them out of the results, so
 * ticking several items across different searches never loses the earlier ones. Labels for ids
 * the caller already had (e.g. a cross-navigation link's ?item_id=) are resolved via fetchItem,
 * one query per id, cached by react-query like everywhere else in this app.
 */
export function ItemMultiFilter({ value, onChange, className }: ItemMultiFilterProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<{ value: string; label: string }[]>([])
  const [searching, setSearching] = useState(false)
  const requestId = useRef(0)

  useEffect(() => {
    if (!open) return
    const id = ++requestId.current
    setSearching(true)
    const handle = setTimeout(() => {
      searchItemsLookup(query.trim())
        .then((items) => {
          if (requestId.current === id) setResults(items.map((item) => ({ value: item.id, label: itemLabel(item) })))
        })
        .finally(() => {
          if (requestId.current === id) setSearching(false)
        })
    }, 250)

    return () => clearTimeout(handle)
  }, [query, open])

  const selectedSet = useMemo(() => new Set(value), [value])

  const selectedItemQueries = useQueries({
    queries: value.map((id) => ({ queryKey: ['item', id], queryFn: () => fetchItem(id) })),
  })
  const selectedOptions = value.map((id, index) => ({
    value: id,
    label: selectedItemQueries[index].data ? itemLabel(selectedItemQueries[index].data!) : id,
  }))

  // Selected rows pinned to the top regardless of the current search, then unselected search results.
  const rows = [...selectedOptions, ...results.filter((r) => !selectedSet.has(r.value))]

  const toggle = (id: string) => onChange(selectedSet.has(id) ? value.filter((v) => v !== id) : [...value, id])
  const clearAll = () => onChange([])

  const label = selectedOptions.length === 0 ? 'All items' : selectedOptions.length === 1 ? selectedOptions[0].label : `${selectedOptions.length} item dipilih`

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-xs text-muted-foreground">Item</span>
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
            aria-label="Item"
            className={cn('w-full justify-between font-normal', selectedOptions.length === 0 && 'text-muted-foreground', className)}
          >
            <span className="truncate">{label}</span>
            <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent className="w-(--radix-dropdown-menu-trigger-width) p-0 duration-0" align="start" side="bottom" avoidCollisions={false}>
          <div className="p-1.5">
            <Input autoFocus value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari…" className="h-8" aria-label="Cari" />
          </div>
          {selectedOptions.length > 0 && (
            <div className="border-b px-1.5 pb-1.5">
              <button type="button" className="rounded-sm px-2 py-1 text-xs text-muted-foreground hover:bg-accent" onClick={clearAll}>
                Hapus pilihan
              </button>
            </div>
          )}
          <div role="listbox" aria-multiselectable className="max-h-64 overflow-y-auto p-1">
            {searching && rows.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">Memuat…</p>}
            {!searching && rows.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">Tidak ada data yang cocok</p>}
            {rows.map((option) => {
              const checked = selectedSet.has(option.value)
              return (
                <button
                  key={option.value}
                  type="button"
                  role="option"
                  aria-selected={checked}
                  className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                  onClick={() => toggle(option.value)}
                >
                  <Checkbox checked={checked} className="pointer-events-none" />
                  <span className="truncate">{option.label}</span>
                </button>
              )
            })}
          </div>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
