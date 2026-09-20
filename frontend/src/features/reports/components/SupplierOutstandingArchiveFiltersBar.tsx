import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { emptySupplierOutstandingArchiveFilters, hasActiveSupplierOutstandingArchiveFilters } from '../lib/reportFilters'
import type { SupplierOutstandingArchiveFilterValues, SupplierOutstandingArchiveStatus } from '../types'

const ALL = '__all__'

interface SupplierOutstandingArchiveFiltersBarProps {
  value: SupplierOutstandingArchiveFilterValues
  onChange: (value: SupplierOutstandingArchiveFilterValues) => void
}

/** AP mirror of CustomerOutstandingArchiveFiltersBar -- Supplier here is a plain snapshotted string (code/name), not a master-data FK. */
export function SupplierOutstandingArchiveFiltersBar({ value, onChange }: SupplierOutstandingArchiveFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptySupplierOutstandingArchiveFilters)} hasActiveFilters={hasActiveSupplierOutstandingArchiveFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Supplier</span>
        <Input
          className="w-56"
          placeholder="Kode atau nama supplier…"
          value={value.supplier}
          onChange={(event) => onChange({ ...value, supplier: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.status ?? ALL}
          onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as SupplierOutstandingArchiveStatus) })}
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
