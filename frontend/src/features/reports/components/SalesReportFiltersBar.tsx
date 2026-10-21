import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { MultiSelectFilter } from '@/components/shared/MultiSelectFilter'
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
  // Resolves the label for the one customer_id arriving pre-set (URL restore) -- the async
  // multi-select has no other way to know its label without this, same reasoning as AR Detail's
  // own customerMultiSelect wiring.
  const singleCustomerId = value.customer_ids.length === 1 ? value.customer_ids[0] : undefined
  const selectedCustomerQuery = useQuery({
    queryKey: ['customer', singleCustomerId],
    queryFn: () => fetchCustomer(singleCustomerId!),
    enabled: shown('customer') && !!singleCustomerId,
  })

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: item.item_name }))
  }
  const singleItemId = value.item_ids.length === 1 ? value.item_ids[0] : undefined
  const selectedItemQuery = useQuery({
    queryKey: ['item', singleItemId],
    queryFn: () => fetchItem(singleItemId!),
    enabled: shown('item') && !!singleItemId,
  })

  return (
    <FilterPanel onClear={() => onChange(emptySalesReportFilters())} hasActiveFilters={hasActiveSalesReportFilters(value)}>
      {shown('customer') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Customer</span>
          <MultiSelectFilter
            loadOptions={loadCustomerOptions}
            resolvedSingleLabel={selectedCustomerQuery.data?.customer_name}
            value={value.customer_ids}
            onChange={(next) => onChange({ ...value, customer_ids: next })}
            placeholder="All customers"
            itemLabel="customer"
            aria-label="Customer"
            className="w-44"
          />
        </div>
      )}
      {shown('item') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Item</span>
          <MultiSelectFilter
            loadOptions={loadItemOptions}
            resolvedSingleLabel={selectedItemQuery.data?.item_name}
            value={value.item_ids}
            onChange={(next) => onChange({ ...value, item_ids: next })}
            placeholder="All items"
            itemLabel="item"
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
          <MultiSelectFilter
            options={salesPersons.data?.map((salesPerson) => ({ value: salesPerson.id, label: salesPerson.name })) ?? []}
            value={value.sales_person_ids}
            onChange={(next) => onChange({ ...value, sales_person_ids: next })}
            loading={salesPersons.isLoading}
            placeholder="All sales persons"
            itemLabel="salesman"
            aria-label="Sales Person"
            className="w-44"
          />
        </div>
      )}
      {shown('branch') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Branch</span>
          <MultiSelectFilter
            options={branches.data?.map((branch) => ({ value: branch.id, label: branch.name })) ?? []}
            value={value.branch_ids}
            onChange={(next) => onChange({ ...value, branch_ids: next })}
            loading={branches.isLoading}
            placeholder="All branches"
            itemLabel="branch"
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
