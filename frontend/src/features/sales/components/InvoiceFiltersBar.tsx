import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { emptyInvoiceFilters, hasActiveInvoiceFilters } from '../lib/invoiceFilters'
import type { DocumentStatus, InvoiceFilterValues } from '../types'

const ALL = '__all__'

interface InvoiceFiltersBarProps {
  value: InvoiceFilterValues
  onChange: (value: InvoiceFilterValues) => void
}

/** Invoice's own status field (draft/submitted/cancelled) is unchanged by the SO/DO status rework — was previously (mis)borrowing SalesOrderFiltersBar, which now has its own diverged Submitted/Approved/Cancelled vocabulary. */
export function InvoiceFiltersBar({ value, onChange }: InvoiceFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptyInvoiceFilters)} hasActiveFilters={hasActiveInvoiceFilters(value)}>
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
