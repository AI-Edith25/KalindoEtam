import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { emptySalesTargetFilters, hasActiveSalesTargetFilters, type SalesTargetFilterValues } from '../lib/salesTargetFilters'
import { MONTH_OPTIONS } from '../lib/months'

const ALL = '__all__'

interface SalesTargetFiltersBarProps {
  value: SalesTargetFilterValues
  onChange: (value: SalesTargetFilterValues) => void
  salesPersonOptions: { value: string; label: string }[]
}

/** Same server-side-free, client-only filter contract as SalesPersonFiltersBar — values narrow whatever the current page already has. */
export function SalesTargetFiltersBar({ value, onChange, salesPersonOptions }: SalesTargetFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptySalesTargetFilters)} hasActiveFilters={hasActiveSalesTargetFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Month</span>
        <Select
          value={value.periodMonth === null ? ALL : String(value.periodMonth)}
          onValueChange={(next) => onChange({ ...value, periodMonth: next === ALL ? null : Number(next) })}
        >
          <SelectTrigger className="w-36">
            <SelectValue placeholder="All months" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All months</SelectItem>
            {MONTH_OPTIONS.map((month) => (
              <SelectItem key={month.value} value={String(month.value)}>
                {month.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Year</span>
        <Input
          type="number"
          className="w-24"
          placeholder="All years"
          value={value.periodYear ?? ''}
          onChange={(event) => onChange({ ...value, periodYear: event.target.value === '' ? null : Number(event.target.value) })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Sales Person</span>
        <Select
          value={value.salesPersonId ?? ALL}
          onValueChange={(next) => onChange({ ...value, salesPersonId: next === ALL ? null : next })}
        >
          <SelectTrigger className="w-48">
            <SelectValue placeholder="All sales persons" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All sales persons</SelectItem>
            {salesPersonOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
