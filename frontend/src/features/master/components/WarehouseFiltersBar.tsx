import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { emptyWarehouseFilters, hasActiveWarehouseFilters, type WarehouseFilterValues } from '../lib/warehouseFilters'
import type { WarehouseType } from '../types'

const ALL = '__all__'

interface WarehouseFiltersBarProps {
  value: WarehouseFilterValues
  onChange: (value: WarehouseFilterValues) => void
}

export function WarehouseFiltersBar({ value, onChange }: WarehouseFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptyWarehouseFilters)} hasActiveFilters={hasActiveWarehouseFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Type</span>
        <Select
          value={value.warehouseType ?? ALL}
          onValueChange={(next) => onChange({ warehouseType: next === ALL ? null : (next as WarehouseType) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All types" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="main">Main</SelectItem>
            <SelectItem value="transit">Transit</SelectItem>
            <SelectItem value="return">Return</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
