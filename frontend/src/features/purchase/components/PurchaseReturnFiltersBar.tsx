import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { emptyPurchaseReturnFilters, hasActivePurchaseReturnFilters } from '../lib/purchaseReturnFilters'
import { PURCHASE_RETURN_REASON_OPTIONS } from '../lib/purchaseReturnReasonLabels'
import type { DocumentStatus, PurchaseReturnFilterValues, PurchaseReturnReason } from '../types'

const ALL = '__all__'

interface PurchaseReturnFiltersBarProps {
  value: PurchaseReturnFilterValues
  onChange: (value: PurchaseReturnFilterValues) => void
}

/** Same server-side filter contract as sales/components/CreditNoteFiltersBar.tsx. */
export function PurchaseReturnFiltersBar({ value, onChange }: PurchaseReturnFiltersBarProps) {
  return (
    <FilterPanel onClear={() => onChange(emptyPurchaseReturnFilters)} hasActiveFilters={hasActivePurchaseReturnFilters(value)}>
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
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Reason</span>
        <Select
          value={value.reason ?? ALL}
          onValueChange={(next) => onChange({ ...value, reason: next === ALL ? null : (next as PurchaseReturnReason) })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder="All reasons" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All reasons</SelectItem>
            {PURCHASE_RETURN_REASON_OPTIONS.map(([reasonValue, label]) => (
              <SelectItem key={reasonValue} value={reasonValue}>
                {label}
              </SelectItem>
            ))}
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
