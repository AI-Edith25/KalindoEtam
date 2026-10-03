import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchItemGroups } from '@/features/master/api/lookupsApi'
import { useWarehousesLookup } from '@/features/master/hooks/useLookups'
import { emptyStockValuationFilters, hasActiveStockValuationFilters } from '../lib/stockValuationFilters'
import { ItemMultiFilter } from './ItemMultiFilter'
import type { StockValuationFilterValues } from '../types'

interface StockValuationFiltersBarProps {
  value: StockValuationFilterValues
  onChange: (value: StockValuationFilterValues) => void
}

export function StockValuationFiltersBar({ value, onChange }: StockValuationFiltersBarProps) {
  const warehouses = useWarehousesLookup()
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []
  const itemGroups = useQuery({ queryKey: ['item-groups-lookup'], queryFn: fetchItemGroups })
  const itemGroupOptions = itemGroups.data?.map((group) => ({ value: group.id, label: group.name })) ?? []

  return (
    <FilterPanel onClear={() => onChange(emptyStockValuationFilters)} hasActiveFilters={hasActiveStockValuationFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">From</span>
        <Input type="date" className="w-40" value={value.dateFrom} onChange={(event) => onChange({ ...value, dateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Location</span>
        <SearchableSelect
          options={warehouseOptions}
          value={value.warehouse_id || undefined}
          onChange={(next) => onChange({ ...value, warehouse_id: next ?? '' })}
          loading={warehouses.isLoading}
          className="w-44"
          placeholder="All locations"
          aria-label="Location"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Item Group</span>
        <SearchableSelect
          options={itemGroupOptions}
          value={value.item_group_id || undefined}
          onChange={(next) => onChange({ ...value, item_group_id: next ?? '' })}
          loading={itemGroups.isLoading}
          className="w-44"
          placeholder="All item groups"
          aria-label="Item Group"
        />
      </div>
      <ItemMultiFilter value={value.item_id} onChange={(next) => onChange({ ...value, item_id: next })} className="w-44" />
    </FilterPanel>
  )
}
