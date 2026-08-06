import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchCustomersLookup } from '@/features/master/api/lookupsApi'
import { emptyArAgingReportFilters, hasActiveArAgingReportFilters } from '../lib/reportFilters'
import type { ArAgingReportFilterValues } from '../types'

const ALL = '__all__'

interface AccountsReceivableAgingFiltersBarProps {
  value: ArAgingReportFilterValues
  onChange: (value: ArAgingReportFilterValues) => void
}

/** Own filter set for this report — a point-in-time snapshot (As Of date), not a date range, unlike every other report in this batch. */
export function AccountsReceivableAgingFiltersBar({ value, onChange }: AccountsReceivableAgingFiltersBarProps) {
  const customers = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyArAgingReportFilters)} hasActiveFilters={hasActiveArAgingReportFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Customer</span>
        <Select
          value={value.customer_id || ALL}
          onValueChange={(next) => onChange({ ...value, customer_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={customers.isLoading ? 'Loading…' : 'All customers'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All customers</SelectItem>
            {customers.data?.map((customer) => (
              <SelectItem key={customer.id} value={customer.id}>
                {customer.customer_name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">As Of</span>
        <Input
          type="date"
          className="w-40"
          value={value.asOfDate}
          onChange={(event) => onChange({ ...value, asOfDate: event.target.value })}
        />
      </div>
    </FilterPanel>
  )
}
