import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchBranches, fetchCustomersLookup, fetchItemsLookup, fetchSalesPersonsLookup } from '@/features/master/api/lookupsApi'
import type { DocumentStatus } from '@/features/sales/types'
import { emptySalesReportFilters, hasActiveSalesReportFilters } from '../lib/reportFilters'
import type { SalesReportFilterValues } from '../types'

const ALL = '__all__'

interface SalesReportFiltersBarProps {
  value: SalesReportFilterValues
  onChange: (value: SalesReportFilterValues) => void
}

export function SalesReportFiltersBar({ value, onChange }: SalesReportFiltersBarProps) {
  const customers = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup })
  const salesPersons = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })

  return (
    <FilterPanel onClear={() => onChange(emptySalesReportFilters)} hasActiveFilters={hasActiveSalesReportFilters(value)}>
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
        <span className="text-xs text-muted-foreground">Item</span>
        <Select
          value={value.item_id || ALL}
          onValueChange={(next) => onChange({ ...value, item_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={items.isLoading ? 'Loading…' : 'All items'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All items</SelectItem>
            {items.data?.map((item) => (
              <SelectItem key={item.id} value={item.id}>
                {item.item_name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Sales Person</span>
        <Select
          value={value.sales_person_id || ALL}
          onValueChange={(next) => onChange({ ...value, sales_person_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={salesPersons.isLoading ? 'Loading…' : 'All sales persons'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All sales persons</SelectItem>
            {salesPersons.data?.map((salesPerson) => (
              <SelectItem key={salesPerson.id} value={salesPerson.id}>
                {salesPerson.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Branch</span>
        <Select
          value={value.branch_id || ALL}
          onValueChange={(next) => onChange({ ...value, branch_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={branches.isLoading ? 'Loading…' : 'All branches'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All branches</SelectItem>
            {branches.data?.map((branch) => (
              <SelectItem key={branch.id} value={branch.id}>
                {branch.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.status ?? ALL}
          onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as DocumentStatus) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All statuses</SelectItem>
            <SelectItem value="draft">Draft</SelectItem>
            <SelectItem value="submitted">Submitted</SelectItem>
            <SelectItem value="cancelled">Cancelled</SelectItem>
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
