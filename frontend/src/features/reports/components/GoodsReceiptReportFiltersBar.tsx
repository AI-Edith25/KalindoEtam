import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { emptyGoodsReceiptReportFilters, hasActiveGoodsReceiptReportFilters } from '../lib/reportFilters'
import type { GoodsReceiptReportFilterValues } from '../types'

const ALL = '__all__'

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
