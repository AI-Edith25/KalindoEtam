import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchCustomersLookup } from '@/features/master/api/lookupsApi'
import type { SettlementStatus } from '@/features/payment/types'
import { emptyArDetailReportFilters, hasActiveArDetailReportFilters } from '../lib/reportFilters'
import type { ArDetailReportFilterValues } from '../types'

const ALL = '__all__'

interface AccountsReceivableDetailReportFiltersBarProps {
  value: ArDetailReportFilterValues
  onChange: (value: ArDetailReportFilterValues) => void
}

/** Own filter set for this report — deliberately not a shared generic filter engine, matching every other report's FiltersBar in this codebase. */
export function AccountsReceivableDetailReportFiltersBar({ value, onChange }: AccountsReceivableDetailReportFiltersBarProps) {
  const customers = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyArDetailReportFilters)} hasActiveFilters={hasActiveArDetailReportFilters(value)}>
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
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.status ?? ALL}
          onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as SettlementStatus) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All statuses</SelectItem>
            <SelectItem value="unpaid">Unpaid</SelectItem>
            <SelectItem value="partially_paid">Partially Paid</SelectItem>
            <SelectItem value="paid">Paid</SelectItem>
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
