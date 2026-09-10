import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchCustomer } from '@/features/master/api/customerApi'
import { fetchBranches, fetchSalesPersonsLookup, searchCustomersLookup } from '@/features/master/api/lookupsApi'
import type { SettlementStatus } from '@/features/payment/types'
import { emptyArDetailReportFilters, hasActiveArDetailReportFilters } from '../lib/reportFilters'
import type { AgingBucketValue, ArDetailReportFilterValues } from '../types'

const ALL = '__all__'

interface AccountsReceivableDetailReportFiltersBarProps {
  value: ArDetailReportFilterValues
  onChange: (value: ArDetailReportFilterValues) => void
}

function customerLabel(customer: { customer_code: string; customer_name: string }) {
  return `${customer.customer_code} — ${customer.customer_name}`
}

/** Own filter set for this report — deliberately not a shared generic filter engine, matching every other report's FiltersBar in this codebase. */
export function AccountsReceivableDetailReportFiltersBar({ value, onChange }: AccountsReceivableDetailReportFiltersBarProps) {
  const salesPersons = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })

  // Master Customer runs into the thousands — server-side search (SearchableSelect's async mode)
  // rather than a client-filtered lookup list.
  const loadCustomerOptions = async (query: string) => {
    const customers = await searchCustomersLookup(query)
    return customers.map((customer) => ({ value: customer.id, label: customerLabel(customer) }))
  }

  // Resolves the label for a customer_id arriving pre-set (URL restore) — the async dropdown has
  // no other way to know its label without this.
  const selectedCustomerQuery = useQuery({
    queryKey: ['customer', value.customer_id],
    queryFn: () => fetchCustomer(value.customer_id),
    enabled: !!value.customer_id,
  })
  const selectedCustomerOption = selectedCustomerQuery.data
    ? { value: selectedCustomerQuery.data.id, label: customerLabel(selectedCustomerQuery.data) }
    : undefined

  return (
    <FilterPanel onClear={() => onChange(emptyArDetailReportFilters)} hasActiveFilters={hasActiveArDetailReportFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Customer</span>
        <SearchableSelect
          loadOptions={loadCustomerOptions}
          selectedOption={selectedCustomerOption}
          value={value.customer_id || undefined}
          onChange={(next) => onChange({ ...value, customer_id: next ?? '' })}
          placeholder="All customers"
          aria-label="Customer"
          className="w-56"
        />
      </div>
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
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Salesman</span>
        <SearchableSelect
          options={salesPersons.data?.map((salesPerson) => ({ value: salesPerson.id, label: salesPerson.name })) ?? []}
          value={value.sales_person_id || undefined}
          onChange={(next) => onChange({ ...value, sales_person_id: next ?? '' })}
          loading={salesPersons.isLoading}
          placeholder="All sales persons"
          aria-label="Salesman"
          className="w-44"
        />
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
        <span className="text-xs text-muted-foreground">Aging</span>
        <Select
          value={value.agingBucket ?? ALL}
          onValueChange={(next) => onChange({ ...value, agingBucket: next === ALL ? null : (next as AgingBucketValue) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All</SelectItem>
            <SelectItem value="30">30 Days</SelectItem>
            <SelectItem value="45">45 Days</SelectItem>
            <SelectItem value="60">60 Days</SelectItem>
            <SelectItem value="90">90 Days</SelectItem>
            <SelectItem value="over_180">Over 180 Days</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Due Date From</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateFrom}
          onChange={(event) => onChange({ ...value, dateFrom: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Due Date To</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateTo}
          onChange={(event) => onChange({ ...value, dateTo: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Invoice Date From</span>
        <Input
          type="date"
          className="w-40"
          value={value.invoiceDateFrom}
          onChange={(event) => onChange({ ...value, invoiceDateFrom: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Invoice Date To</span>
        <Input
          type="date"
          className="w-40"
          value={value.invoiceDateTo}
          onChange={(event) => onChange({ ...value, invoiceDateTo: event.target.value })}
        />
      </div>
    </FilterPanel>
  )
}
