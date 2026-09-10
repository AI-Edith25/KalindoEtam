import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { emptyGoodsReceiptReportFilters, hasActiveGoodsReceiptReportFilters } from '../lib/reportFilters'
import type { GoodsReceiptReportFilterValues } from '../types'

interface GoodsReceiptReportFiltersBarProps {
  value: GoodsReceiptReportFilterValues
  onChange: (value: GoodsReceiptReportFilterValues) => void
}

export function GoodsReceiptReportFiltersBar({ value, onChange }: GoodsReceiptReportFiltersBarProps) {
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })

  return (
    <FilterPanel
      onClear={() => onChange(emptyGoodsReceiptReportFilters)}
      hasActiveFilters={hasActiveGoodsReceiptReportFilters(value)}
    >
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Warehouse</span>
        <SearchableSelect
          options={warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []}
          value={value.warehouse_id || undefined}
          onChange={(next) => onChange({ ...value, warehouse_id: next ?? '' })}
          loading={warehouses.isLoading}
          placeholder="All warehouses"
          aria-label="Warehouse"
          className="w-44"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">From</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateFrom}
          onChange={(event) => onChange({ ...value, dateFrom: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateTo}
          onChange={(event) => onChange({ ...value, dateTo: event.target.value })}
        />
      </div>
    </FilterPanel>
  )
}
