import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { fetchItemGroups, fetchUoms } from '../api/lookupsApi'
import { emptyItemFilters, hasActiveItemFilters, type ItemFilterValues } from '../lib/itemFilters'

const ALL = '__all__'

interface ItemFiltersBarProps {
  value: ItemFilterValues
  onChange: (value: ItemFilterValues) => void
}

/**
 * Pure controlled UI — knows nothing about how `value` gets applied
 * (client-side today, a query param later). Swapping the filtering
 * strategy only ever touches itemFilters.ts / ItemListPage, never this
 * component.
 */
export function ItemFiltersBar({ value, onChange }: ItemFiltersBarProps) {
  const itemGroups = useQuery({ queryKey: ['item-groups'], queryFn: fetchItemGroups })
  const uoms = useQuery({ queryKey: ['uoms'], queryFn: fetchUoms })

  return (
    <FilterPanel onClear={() => onChange(emptyItemFilters)} hasActiveFilters={hasActiveItemFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Item Group</span>
        <SearchableSelect
          options={itemGroups.data?.map((group) => ({ value: group.id, label: group.name })) ?? []}
          value={value.itemGroupId ?? undefined}
          onChange={(next) => onChange({ ...value, itemGroupId: next ?? null })}
          placeholder="All item groups"
          className="w-44"
          aria-label="Item Group"
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">UOM</span>
        <SearchableSelect
          options={uoms.data?.map((uom) => ({ value: uom.id, label: uom.name })) ?? []}
          value={value.uomId ?? undefined}
          onChange={(next) => onChange({ ...value, uomId: next ?? null })}
          placeholder="All UOMs"
          className="w-44"
          aria-label="UOM"
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Qty Category</span>
        <Select
          value={value.qtyCategory ?? ALL}
          onValueChange={(next) => onChange({ ...value, qtyCategory: next === ALL ? null : (next as 'unit' | 'weight') })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder="All categories" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All categories</SelectItem>
            <SelectItem value="unit">Unit</SelectItem>
            <SelectItem value="weight">Weight</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
