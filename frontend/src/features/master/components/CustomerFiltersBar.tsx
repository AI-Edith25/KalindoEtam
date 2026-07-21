import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { emptyCustomerFilters, hasActiveCustomerFilters, type CustomerFilterValues } from '../lib/customerFilters'

const ALL = '__all__'

interface CustomerFiltersBarProps {
  value: CustomerFilterValues
  onChange: (value: CustomerFilterValues) => void
}

export function CustomerFiltersBar({ value, onChange }: CustomerFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptyCustomerFilters)} hasActiveFilters={hasActiveCustomerFilters(value)}>
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
