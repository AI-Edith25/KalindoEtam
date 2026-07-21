import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { emptySupplierFilters, hasActiveSupplierFilters, type SupplierFilterValues } from '../lib/supplierFilters'

const ALL = '__all__'

interface SupplierFiltersBarProps {
  value: SupplierFilterValues
  onChange: (value: SupplierFilterValues) => void
}

export function SupplierFiltersBar({ value, onChange }: SupplierFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptySupplierFilters)} hasActiveFilters={hasActiveSupplierFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.isActive === null ? ALL : value.isActive ? 'active' : 'inactive'}
          onValueChange={(next) => onChange({ isActive: next === ALL ? null : next === 'active' })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All statuses</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
