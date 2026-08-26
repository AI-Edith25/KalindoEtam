import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { emptyPurchaseInvoiceFilters, hasActivePurchaseInvoiceFilters } from '../lib/purchaseInvoiceFilters'
import type { DocumentStatus, PurchaseInvoiceFilterValues } from '../types'

const ALL = '__all__'

interface PurchaseInvoiceFiltersBarProps {
  value: PurchaseInvoiceFilterValues
  onChange: (value: PurchaseInvoiceFilterValues) => void
}

/** Same server-side filter contract as sales/components/InvoiceFiltersBar.tsx. */
export function PurchaseInvoiceFiltersBar({ value, onChange }: PurchaseInvoiceFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptyPurchaseInvoiceFilters)} hasActiveFilters={hasActivePurchaseInvoiceFilters(value)}>
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
        <Input type="date" className="w-40" value={value.dateFrom} onChange={(event) => onChange({ ...value, dateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
      </div>
    </FilterPanel>
  )
}
