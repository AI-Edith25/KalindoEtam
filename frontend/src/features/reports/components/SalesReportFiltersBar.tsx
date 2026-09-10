import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchCustomer } from '@/features/master/api/customerApi'
import { fetchItem } from '@/features/master/api/itemApi'
import { fetchBranches, fetchItemGroups, fetchSalesPersonsLookup, searchCustomersLookup, searchItemsLookup } from '@/features/master/api/lookupsApi'
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
  const itemGroups = useQuery({ queryKey: ['item-groups'], queryFn: fetchItemGroups, enabled: !hide.includes('itemGroup') })
  const salesPersons = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup, enabled: !hide.includes('salesPerson') })
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches, enabled: !hide.includes('branch') })

  const shown = (field: SalesReportFilterField) => !hide.includes(field)

  const loadCustomerOptions = async (query: string) => {
    const customers = await searchCustomersLookup(query)
    return customers.map((customer) => ({ value: customer.id, label: customer.customer_name }))
  }
  const selectedCustomerQuery = useQuery({
    queryKey: ['customer', value.customer_id],
    queryFn: () => fetchCustomer(value.customer_id),
    enabled: shown('customer') && !!value.customer_id,
  })
  const selectedCustomerOption = selectedCustomerQuery.data
    ? { value: selectedCustomerQuery.data.id, label: selectedCustomerQuery.data.customer_name }
    : undefined

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: item.item_name }))
  }
  const selectedItemQuery = useQuery({
    queryKey: ['item', value.item_id],
    queryFn: () => fetchItem(value.item_id),
    enabled: shown('item') && !!value.item_id,
  })
  const selectedItemOption = selectedItemQuery.data
    ? { value: selectedItemQuery.data.id, label: selectedItemQuery.data.item_name }
    : undefined

  return (
    <FilterPanel onClear={() => onChange(emptySalesReportFilters())} hasActiveFilters={hasActiveSalesReportFilters(value)}>
      {shown('customer') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Customer</span>
          <SearchableSelect
            loadOptions={loadCustomerOptions}
            selectedOption={selectedCustomerOption}
            value={value.customer_id || undefined}
            onChange={(next) => onChange({ ...value, customer_id: next ?? '' })}
            placeholder="All customers"
            aria-label="Customer"
            className="w-44"
          />
        </div>
      )}
      {shown('item') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Item</span>
          <SearchableSelect
            loadOptions={loadItemOptions}
            selectedOption={selectedItemOption}
            value={value.item_id || undefined}
            onChange={(next) => onChange({ ...value, item_id: next ?? '' })}
            placeholder="All items"
            aria-label="Item"
            className="w-44"
          />
        </div>
      )}
      {shown('itemGroup') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Item Group</span>
          <SearchableSelect
            options={itemGroups.data?.map((group) => ({ value: group.id, label: group.name })) ?? []}
            value={value.item_group_id || undefined}
            onChange={(next) => onChange({ ...value, item_group_id: next ?? '' })}
            loading={itemGroups.isLoading}
            placeholder="All item groups"
            aria-label="Item Group"
            className="w-44"
          />
        </div>
      )}
      {shown('salesPerson') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Sales Person</span>
          <SearchableSelect
            options={salesPersons.data?.map((salesPerson) => ({ value: salesPerson.id, label: salesPerson.name })) ?? []}
            value={value.sales_person_id || undefined}
            onChange={(next) => onChange({ ...value, sales_person_id: next ?? '' })}
            loading={salesPersons.isLoading}
            placeholder="All sales persons"
            aria-label="Sales Person"
            className="w-44"
          />
        </div>
      )}
      {shown('branch') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Branch</span>
          <SearchableSelect
            options={branches.data?.map((branch) => ({ value: branch.id, label: branch.name })) ?? []}
            value={value.branch_id || undefined}
            onChange={(next) => onChange({ ...value, branch_id: next ?? '' })}
            loading={branches.isLoading}
            placeholder="All branches"
            aria-label="Branch"
            className="w-44"
          />
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
