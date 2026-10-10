import type { ReactNode } from 'react'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { emptyJournalEntryFilters, hasActiveJournalEntryFilters } from '../lib/journalEntryFilters'
import type { DocumentStatus, JournalEntryFilterValues } from '../types'

const ALL = '__all__'

interface JournalEntryFiltersBarProps {
  value: JournalEntryFilterValues
  onChange: (value: JournalEntryFilterValues) => void
  /** The Journal Type select, rendered first in the same filter row — see JournalListPage. */
  leading?: ReactNode
}

export function JournalEntryFiltersBar({ value, onChange, leading }: JournalEntryFiltersBarProps) {
  const accounts = useChartOfAccountsLookup()

  return (
    <FilterPanel onClear={() => onChange(emptyJournalEntryFilters)} hasActiveFilters={hasActiveJournalEntryFilters(value)}>
      {leading}
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.status ?? ALL}
          onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as DocumentStatus) })}
        >
          <SelectTrigger className="w-36">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All statuses</SelectItem>
            <SelectItem value="draft">Draft</SelectItem>
            <SelectItem value="submitted">Posted</SelectItem>
            <SelectItem value="cancelled">Cancelled</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Account</span>
        <SearchableSelect
          options={accounts.data?.map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` })) ?? []}
          value={value.accountId ?? undefined}
          onChange={(next) => onChange({ ...value, accountId: next ?? null })}
          loading={accounts.isLoading}
          placeholder="All accounts"
          className="w-48"
          aria-label="Account"
        />
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
