import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { fetchItemGroups, searchItemsLookup } from '@/features/master/api/lookupsApi'
import { useWarehousesLookup } from '@/features/master/hooks/useLookups'
import { emptyStockBalanceFilters, hasActiveStockBalanceFilters } from '../lib/stockBalanceFilters'
import type { StockBalanceFilterValues } from '../types'

function itemLabel(item: { item_code: string; item_name: string }) {
  return `${item.item_code} — ${item.item_name}`
}

interface StockBalanceFiltersBarProps {
  value: StockBalanceFilterValues
  onChange: (value: StockBalanceFilterValues) => void
}

export function StockBalanceFiltersBar({ value, onChange }: StockBalanceFiltersBarProps) {
  const warehouses = useWarehousesLookup()
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []
  const itemGroups = useQuery({ queryKey: ['item-groups-lookup'], queryFn: fetchItemGroups })
  const itemGroupOptions = itemGroups.data?.map((group) => ({ value: group.id, label: group.name })) ?? []

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: itemLabel(item) }))
  }

  return (
    <FilterPanel onClear={() => onChange(emptyStockBalanceFilters)} hasActiveFilters={hasActiveStockBalanceFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Warehouse</span>
        <SearchableSelect
          options={warehouseOptions}
          value={value.warehouse_id || undefined}
          onChange={(next) => onChange({ ...value, warehouse_id: next ?? '' })}
          loading={warehouses.isLoading}
          className="w-44"
          placeholder="All warehouses"
          aria-label="Warehouse"
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
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Item</span>
        <SearchableSelect
          loadOptions={loadItemOptions}
          value={value.item_id || undefined}
          onChange={(next) => onChange({ ...value, item_id: next ?? '' })}
          className="w-44"
          placeholder="All items"
          aria-label="Item"
        />
      </div>
    </FilterPanel>
  )
}
