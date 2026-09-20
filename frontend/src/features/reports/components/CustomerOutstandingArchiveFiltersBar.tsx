import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { emptyCustomerOutstandingArchiveFilters, hasActiveCustomerOutstandingArchiveFilters } from '../lib/reportFilters'
import type { CustomerOutstandingArchiveFilterValues, CustomerOutstandingArchiveStatus } from '../types'

const ALL = '__all__'

interface CustomerOutstandingArchiveFiltersBarProps {
  value: CustomerOutstandingArchiveFilterValues
  onChange: (value: CustomerOutstandingArchiveFilterValues) => void
}

/** Customer here is a plain snapshotted string (code/name), not a master-data FK -- a free-text field, not a SearchableSelect against Customer master. */
export function CustomerOutstandingArchiveFiltersBar({ value, onChange }: CustomerOutstandingArchiveFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptyCustomerOutstandingArchiveFilters)} hasActiveFilters={hasActiveCustomerOutstandingArchiveFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Customer</span>
        <Input
          className="w-56"
          placeholder="Kode atau nama customer…"
          value={value.customer}
          onChange={(event) => onChange({ ...value, customer: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.status ?? ALL}
          onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as CustomerOutstandingArchiveStatus) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All statuses</SelectItem>
            <SelectItem value="outstanding">Outstanding</SelectItem>
            <SelectItem value="overdue">Overdue</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Invoice Date From</span>
        <Input type="date" className="w-40" value={value.invoiceDateFrom} onChange={(event) => onChange({ ...value, invoiceDateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Invoice Date To</span>
        <Input type="date" className="w-40" value={value.invoiceDateTo} onChange={(event) => onChange({ ...value, invoiceDateTo: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Due Date From</span>
        <Input type="date" className="w-40" value={value.dueDateFrom} onChange={(event) => onChange({ ...value, dueDateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Due Date To</span>
        <Input type="date" className="w-40" value={value.dueDateTo} onChange={(event) => onChange({ ...value, dueDateTo: event.target.value })} />
      </div>
    </FilterPanel>
  )
}
