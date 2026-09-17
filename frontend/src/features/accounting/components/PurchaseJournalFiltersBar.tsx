import type { ReactNode } from 'react'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Input } from '@/components/ui/input'
import type { PurchaseJournalFilterValues } from '../types'

interface PurchaseJournalFiltersBarProps {
  value: PurchaseJournalFilterValues
  onChange: (value: PurchaseJournalFilterValues) => void
  /** The Journal Type select, rendered first in the same filter row — see JournalListPage. */
  leading?: ReactNode
}

/** No Branch filter here, unlike Sales — Purchase has no branch_id anywhere in the schema. */
export function PurchaseJournalFiltersBar({ value, onChange, leading }: PurchaseJournalFiltersBarProps) {
  const hasActiveFilters = value.dateFrom !== '' || value.dateTo !== ''

  return (
    <FilterPanel onClear={() => onChange({ dateFrom: '', dateTo: '' })} hasActiveFilters={hasActiveFilters}>
      {leading}
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
