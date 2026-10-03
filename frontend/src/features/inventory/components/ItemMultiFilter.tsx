import { useQueries } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
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
 * Multiple-item picker for the Inventory Stock report filters (Balance/Ledger/Valuation): the
 * Item master can run into the thousands (same reason SearchableSelect's Item field is async
 * everywhere else), so this can't reuse MultiSelectFilter's sync full-list checkbox dropdown —
 * instead it's a one-at-a-time async add (SearchableSelect never shows a selected value, it's
 * purely an "add item" control) plus removable chips below for whatever's already picked.
 * Labels for ids the caller already had (e.g. a cross-navigation link's ?item_id=) are resolved
 * via fetchItem, one query per id, cached by react-query like everywhere else in this app.
 */
export function ItemMultiFilter({ value, onChange, className }: ItemMultiFilterProps) {
  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: itemLabel(item) }))
  }

  const itemQueries = useQueries({
    queries: value.map((id) => ({ queryKey: ['item', id], queryFn: () => fetchItem(id) })),
  })
  const labels = Object.fromEntries(value.map((id, index) => [id, itemQueries[index].data ? itemLabel(itemQueries[index].data!) : id]))

  const addItem = (id?: string) => {
    if (id && !value.includes(id)) onChange([...value, id])
  }
  const removeItem = (id: string) => onChange(value.filter((v) => v !== id))

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-xs text-muted-foreground">Item</span>
      <SearchableSelect loadOptions={loadItemOptions} value={undefined} onChange={(next) => addItem(next)} className={className} placeholder="Add item…" aria-label="Item" />
      {value.length > 0 && (
        <div className="flex max-w-64 flex-wrap gap-1">
          {value.map((id) => (
            <Badge key={id} variant="secondary" className="gap-1 pr-1">
              <span className="max-w-40 truncate">{labels[id]}</span>
              <button type="button" onClick={() => removeItem(id)} aria-label={`Remove ${labels[id]}`}>
                <X className="size-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  )
}
