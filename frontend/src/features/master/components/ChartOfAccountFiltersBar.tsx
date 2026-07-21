import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { emptyChartOfAccountFilters, hasActiveChartOfAccountFilters, type ChartOfAccountFilterValues } from '../lib/chartOfAccountFilters'
import type { AccountType } from '../types'

const ALL = '__all__'

interface ChartOfAccountFiltersBarProps {
  value: ChartOfAccountFilterValues
  onChange: (value: ChartOfAccountFilterValues) => void
}

export function ChartOfAccountFiltersBar({ value, onChange }: ChartOfAccountFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptyChartOfAccountFilters)} hasActiveFilters={hasActiveChartOfAccountFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Type</span>
        <Select
          value={value.accountType ?? ALL}
          onValueChange={(next) => onChange({ accountType: next === ALL ? null : (next as AccountType) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All types" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="asset">Asset</SelectItem>
            <SelectItem value="liability">Liability</SelectItem>
            <SelectItem value="equity">Equity</SelectItem>
            <SelectItem value="revenue">Revenue</SelectItem>
            <SelectItem value="expense">Expense</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
