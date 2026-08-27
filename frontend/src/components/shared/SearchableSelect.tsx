import { useMemo, useState } from 'react'
import { Check, ChevronsUpDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'

export interface SearchableSelectOption {
  value: string
  label: string
}

interface SearchableSelectProps {
  options: SearchableSelectOption[]
  value?: string
  onChange: (value: string | undefined) => void
  placeholder?: string
  loading?: boolean
  disabled?: boolean
  className?: string
}

/**
 * Type-ahead single-select over an already-fetched option list (Customer,
 * Salesperson, Warehouse — none of those lookup endpoints support
 * server-side search today, see lookupApi.ts) — filters client-side as you
 * type. Built on DropdownMenu + a plain Input rather than Popover/Command
 * (neither is installed in this project) to avoid a new dependency for what
 * amounts to a handful of usages.
 */
export function SearchableSelect({ options, value, onChange, placeholder = 'Select…', loading, disabled, className }: SearchableSelectProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return options
    return options.filter((option) => option.label.toLowerCase().includes(q))
  }, [options, query])

  const selected = options.find((option) => option.value === value)

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
          disabled={disabled || loading}
          className={cn('w-full justify-between font-normal', !selected && 'text-muted-foreground', className)}
        >
          <span className="truncate">{loading ? 'Loading…' : (selected?.label ?? placeholder)}</span>
          <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-(--radix-dropdown-menu-trigger-width) p-0" align="start">
        <div className="p-1.5">
          <Input
            autoFocus
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Cari…"
            className="h-8"
            onKeyDown={(event) => event.stopPropagation()}
          />
        </div>
        <div className="max-h-64 overflow-y-auto p-1">
          {value && (
            <button
              type="button"
              className="flex w-full items-center rounded-sm px-2 py-1.5 text-left text-sm text-muted-foreground hover:bg-accent"
              onClick={() => {
                onChange(undefined)
                setOpen(false)
              }}
            >
              Hapus pilihan
            </button>
          )}
          {filtered.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">Tidak ditemukan.</p>}
          {filtered.map((option) => (
            <button
              key={option.value}
              type="button"
              className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
              onClick={() => {
                onChange(option.value)
                setOpen(false)
              }}
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
