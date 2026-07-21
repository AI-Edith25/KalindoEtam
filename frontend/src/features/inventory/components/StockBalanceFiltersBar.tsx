import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { fetchItemGroups, fetchItemsLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { emptyStockBalanceFilters, hasActiveStockBalanceFilters } from '../lib/stockBalanceFilters'
import type { StockBalanceFilterValues } from '../types'

const ALL = '__all__'

interface StockBalanceFiltersBarProps {
  value: StockBalanceFilterValues
  onChange: (value: StockBalanceFilterValues) => void
}

export function StockBalanceFiltersBar({ value, onChange }: StockBalanceFiltersBarProps) {
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const itemGroups = useQuery({ queryKey: ['item-groups-lookup'], queryFn: fetchItemGroups })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyStockBalanceFilters)} hasActiveFilters={hasActiveStockBalanceFilters(value)}>
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
    </FilterPanel>
  )
}
