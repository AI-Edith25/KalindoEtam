import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { fetchItemGroups, fetchItemsLookup } from '@/features/master/api/lookupsApi'
import { useWarehousesLookup } from '@/features/master/hooks/useLookups'
import { emptyFifoValuationFilters, hasActiveFifoValuationFilters } from '../lib/fifoValuationFilters'
import type { FifoValuationFilterValues } from '../types'

const ALL = '__all__'

interface FifoValuationFiltersBarProps {
  value: FifoValuationFilterValues
  onChange: (value: FifoValuationFilterValues) => void
}

export function FifoValuationFiltersBar({ value, onChange }: FifoValuationFiltersBarProps) {
  const warehouses = useWarehousesLookup()
  const itemGroups = useQuery({ queryKey: ['item-groups-lookup'], queryFn: fetchItemGroups })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: () => fetchItemsLookup() })

  return (
    <FilterPanel onClear={() => onChange(emptyFifoValuationFilters)} hasActiveFilters={hasActiveFifoValuationFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Warehouse</span>
        <Select
          value={value.warehouse_id || ALL}
          onValueChange={(next) => onChange({ ...value, warehouse_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={warehouses.isLoading ? 'Loading…' : 'All warehouses'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All warehouses</SelectItem>
            {warehouses.data?.map((warehouse) => (
              <SelectItem key={warehouse.id} value={warehouse.id}>
                {warehouse.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Item Group</span>
        <Select
          value={value.item_group_id || ALL}
          onValueChange={(next) => onChange({ ...value, item_group_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={itemGroups.isLoading ? 'Loading…' : 'All item groups'} />
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
        <span className="text-xs text-muted-foreground">Item</span>
        <Select value={value.item_id || ALL} onValueChange={(next) => onChange({ ...value, item_id: next === ALL ? '' : next })}>
          <SelectTrigger className="w-44">
            <SelectValue placeholder={items.isLoading ? 'Loading…' : 'All items'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All items</SelectItem>
            {items.data?.map((item) => (
              <SelectItem key={item.id} value={item.id}>
                {item.item_code} — {item.item_name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Received From</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateFrom}
          onChange={(event) => onChange({ ...value, dateFrom: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Received To</span>
        <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
      </div>
      <label className="flex items-center gap-2 self-end pb-1.5 text-sm">
        <Checkbox checked={value.hideExhausted} onCheckedChange={(checked) => onChange({ ...value, hideExhausted: checked === true })} />
        Hide exhausted layers
      </label>
    </FilterPanel>
  )
}
