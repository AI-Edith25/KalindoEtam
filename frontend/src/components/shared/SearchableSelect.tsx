import { useEffect, useMemo, useRef, useState } from 'react'
import { Check, ChevronsUpDown, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'

export interface SearchableSelectOption {
  value: string
  label: string
}

interface SearchableSelectProps {
  /** Sync mode: full option list already fetched — filtered client-side as you type. */
  options?: SearchableSelectOption[]
  /**
   * Async mode: called (debounced ~250ms) with the current query as the user types, for
   * datasets too large to load upfront (e.g. Item, Customer). Mutually exclusive with `options`.
   */
  loadOptions?: (query: string) => Promise<SearchableSelectOption[]>
  /** Async mode only: the currently selected option, so edit mode shows its label without a search round-trip. */
  selectedOption?: SearchableSelectOption
  value?: string
  onChange: (value: string | undefined, option?: SearchableSelectOption) => void
  placeholder?: string
  loading?: boolean
  disabled?: boolean
  /** Hide the "Hapus pilihan" clear row — set false for fields that must always hold a value. */
  clearable?: boolean
  className?: string
  'aria-label'?: string
}

/**
 * Type-ahead single-select. `options` (sync) filters an already-fetched list client-side;
 * `loadOptions` (async) hits the server per keystroke — use it for master data that can
 * outgrow a single page (Item, Customer, ...). Built on DropdownMenu + a plain Input rather
 * than Popover/Command (neither is installed in this project) to avoid a new dependency.
 */
export function SearchableSelect({
  options,
  loadOptions,
  selectedOption,
  value,
  onChange,
  placeholder = 'Select…',
  loading,
  disabled,
  clearable = true,
  className,
  ...rest
}: SearchableSelectProps) {
  const isAsync = !!loadOptions
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [asyncOptions, setAsyncOptions] = useState<SearchableSelectOption[]>([])
  const [searching, setSearching] = useState(false)
  const [highlighted, setHighlighted] = useState(0)
  const requestId = useRef(0)

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

  const filtered = useMemo(() => {
    if (isAsync) return asyncOptions
    const q = query.trim().toLowerCase()
    if (!q) return options ?? []
    return (options ?? []).filter((option) => option.label.toLowerCase().includes(q))
  }, [options, query, isAsync, asyncOptions])

  useEffect(() => setHighlighted(0), [filtered])

  const selected = isAsync
    ? (selectedOption && selectedOption.value === value ? selectedOption : asyncOptions.find((o) => o.value === value))
    : options?.find((o) => o.value === value)

  const selectOption = (option: SearchableSelectOption) => {
    onChange(option.value, option)
    setOpen(false)
  }

  const handleKeyDown = (event: React.KeyboardEvent) => {
    event.stopPropagation()
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setHighlighted((h) => Math.min(h + 1, filtered.length - 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlighted((h) => Math.max(h - 1, 0))
    } else if (event.key === 'Enter') {
      event.preventDefault()
      const option = filtered[highlighted]
      if (option) selectOption(option)
    } else if (event.key === 'Escape') {
      event.preventDefault()
      setOpen(false)
    }
  }

  const isBusy = isAsync ? searching : !!loading

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
          disabled={disabled || (!isAsync && loading)}
          className={cn('w-full justify-between font-normal', !selected && 'text-muted-foreground', className)}
        >
          <span className="truncate">{!isAsync && loading ? 'Loading…' : (selected?.label ?? placeholder)}</span>
          <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        className="w-(--radix-dropdown-menu-trigger-width) p-0 duration-0"
        align="start"
        side="bottom"
        avoidCollisions={false}
      >
        <div className="p-1.5">
          <Input
            autoFocus
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Cari…"
            className="h-8"
            aria-label="Cari"
          />
        </div>
        <div role="listbox" className="max-h-64 overflow-y-auto p-1">
          {clearable && value && (
            <button
              type="button"
              className="flex w-full items-center rounded-sm px-2 py-1.5 text-left text-sm text-muted-foreground hover:bg-accent"
              onClick={() => {
                onChange(undefined, undefined)
                setOpen(false)
              }}
            >
              Hapus pilihan
            </button>
          )}
          {isBusy && filtered.length === 0 && (
            <p className="flex items-center gap-2 px-2 py-1.5 text-sm text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" /> Memuat…
            </p>
          )}
          {!isBusy && filtered.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">Tidak ada data yang cocok</p>}
          {filtered.map((option, index) => (
            <button
              key={option.value}
              type="button"
              role="option"
              aria-selected={option.value === value}
              className={cn('flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent', index === highlighted && 'bg-accent')}
              onMouseEnter={() => setHighlighted(index)}
              onClick={() => selectOption(option)}
            >
              <Check className={cn('size-4 shrink-0', option.value === value ? 'opacity-100' : 'opacity-0')} />
              <span className="truncate">{option.label}</span>
            </button>
          ))}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
