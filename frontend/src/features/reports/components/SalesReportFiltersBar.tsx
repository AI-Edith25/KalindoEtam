import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchBranches, fetchCustomersLookup, fetchItemGroups, fetchItemsLookup, fetchSalesPersonsLookup } from '@/features/master/api/lookupsApi'
import { emptySalesReportFilters, hasActiveSalesReportFilters } from '../lib/reportFilters'
import type { SalesReportFilterValues } from '../types'

const ALL = '__all__'

export type SalesReportFilterField = 'customer' | 'item' | 'itemGroup' | 'salesPerson' | 'branch' | 'status'

interface SalesReportFiltersBarProps {
  value: SalesReportFilterValues
  onChange: (value: SalesReportFilterValues) => void
  /** Fields this tab doesn't use — hidden rather than shown-but-inert, per tab. */
  hide?: SalesReportFilterField[]
  /** Status options for the active tab — Product/Customer/Listing filter Invoice status, Open Orders filters Sales Order status (different value sets). Defaults to Invoice's draft/submitted/cancelled. */
  statusOptions?: { value: string; label: string }[]
}

const DEFAULT_STATUS_OPTIONS = [
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'cancelled', label: 'Cancelled' },
]

export function SalesReportFiltersBar({ value, onChange, hide = [], statusOptions = DEFAULT_STATUS_OPTIONS }: SalesReportFiltersBarProps) {
  const customers = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup, enabled: !hide.includes('customer') })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: () => fetchItemsLookup(), enabled: !hide.includes('item') })
  const itemGroups = useQuery({ queryKey: ['item-groups'], queryFn: fetchItemGroups, enabled: !hide.includes('itemGroup') })
  const salesPersons = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup, enabled: !hide.includes('salesPerson') })
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches, enabled: !hide.includes('branch') })

  const shown = (field: SalesReportFilterField) => !hide.includes(field)

  return (
    <FilterPanel onClear={() => onChange(emptySalesReportFilters())} hasActiveFilters={hasActiveSalesReportFilters(value)}>
      {shown('customer') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Customer</span>
          <Select value={value.customer_id || ALL} onValueChange={(next) => onChange({ ...value, customer_id: next === ALL ? '' : next })}>
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
      )}
      {shown('item') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Item</span>
          <Select value={value.item_id || ALL} onValueChange={(next) => onChange({ ...value, item_id: next === ALL ? '' : next })}>
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
      )}
      {shown('itemGroup') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Item Group</span>
          <Select value={value.item_group_id || ALL} onValueChange={(next) => onChange({ ...value, item_group_id: next === ALL ? '' : next })}>
            <SelectTrigger className="w-44">
              <SelectValue placeholder={itemGroups.isLoading ? 'Loading…' : 'All item groups'} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All item groups</SelectItem>
              {itemGroups.data?.map((group) => (
                <SelectItem key={group.id} value={group.id}>
                  {group.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
      {shown('salesPerson') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Sales Person</span>
          <Select value={value.sales_person_id || ALL} onValueChange={(next) => onChange({ ...value, sales_person_id: next === ALL ? '' : next })}>
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
      )}
      {shown('branch') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Branch</span>
          <Select value={value.branch_id || ALL} onValueChange={(next) => onChange({ ...value, branch_id: next === ALL ? '' : next })}>
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
      )}
      {shown('status') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Status</span>
          <Select value={value.status ?? ALL} onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : next })}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="All statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All statuses</SelectItem>
              {statusOptions.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">From</span>
        <Input type="date" className="w-40" value={value.dateFrom} onChange={(event) => onChange({ ...value, dateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
      </div>
    </FilterPanel>
  )
}
