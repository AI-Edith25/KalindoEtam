import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { emptyJournalEntryFilters, hasActiveJournalEntryFilters } from '../lib/journalEntryFilters'
import type { DocumentStatus, JournalEntryFilterValues } from '../types'

const ALL = '__all__'

interface JournalEntryFiltersBarProps {
  value: JournalEntryFilterValues
  onChange: (value: JournalEntryFilterValues) => void
}

export function JournalEntryFiltersBar({ value, onChange }: JournalEntryFiltersBarProps) {
  const accounts = useChartOfAccountsLookup()

  return (
    <FilterPanel onClear={() => onChange(emptyJournalEntryFilters)} hasActiveFilters={hasActiveJournalEntryFilters(value)}>
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
        <span className="text-xs text-muted-foreground">Reference Type</span>
        <Select
          value={value.referenceType ?? ALL}
          onValueChange={(next) => onChange({ ...value, referenceType: next === ALL ? null : next })}
        >
          <SelectTrigger className="w-36">
            <SelectValue placeholder="All types" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="invoice">Invoice</SelectItem>
            <SelectItem value="receipt_entry">Receipt Entry</SelectItem>
            <SelectItem value="payment_allocation">Payment Allocation</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Account</span>
        <Select
          value={value.accountId ?? ALL}
          onValueChange={(next) => onChange({ ...value, accountId: next === ALL ? null : next })}
        >
          <SelectTrigger className="w-48">
            <SelectValue placeholder={accounts.isLoading ? 'Loading…' : 'All accounts'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All accounts</SelectItem>
            {accounts.data?.map((account) => (
              <SelectItem key={account.id} value={account.id}>
                {account.code} — {account.name}
              </SelectItem>
            ))}
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
