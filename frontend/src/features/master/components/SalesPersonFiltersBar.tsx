import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { emptySalesPersonFilters, hasActiveSalesPersonFilters, type SalesPersonFilterValues } from '../lib/salesPersonFilters'

const ALL = '__all__'

interface SalesPersonFiltersBarProps {
  value: SalesPersonFilterValues
  onChange: (value: SalesPersonFilterValues) => void
}

export function SalesPersonFiltersBar({ value, onChange }: SalesPersonFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptySalesPersonFilters)} hasActiveFilters={hasActiveSalesPersonFilters(value)}>
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
