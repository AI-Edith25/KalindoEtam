import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
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
        <Select
          value={value.itemGroupId ?? ALL}
          onValueChange={(next) => onChange({ ...value, itemGroupId: next === ALL ? null : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder="All item groups" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All item groups</SelectItem>
            {itemGroups.data?.map((group) => (
              <SelectItem key={group.id} value={group.id}>
                {group.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">UOM</span>
        <Select
          value={value.uomId ?? ALL}
          onValueChange={(next) => onChange({ ...value, uomId: next === ALL ? null : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder="All UOMs" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All UOMs</SelectItem>
            {uoms.data?.map((uom) => (
              <SelectItem key={uom.id} value={uom.id}>
                {uom.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
