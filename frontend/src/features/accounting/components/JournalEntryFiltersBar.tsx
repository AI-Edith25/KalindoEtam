import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { useBranchesLookup, useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { emptyJournalEntryFilters, hasActiveJournalEntryFilters } from '../lib/journalEntryFilters'
import type { DocumentStatus, JournalEntryFilterValues } from '../types'

const ALL = '__all__'

const REFERENCE_TYPE_OPTIONS = [
  { value: 'invoice', label: 'Invoice' },
  { value: 'receipt_entry', label: 'Receipt Entry' },
  { value: 'payment_allocation', label: 'Payment Allocation' },
]

interface JournalEntryFiltersBarProps {
  value: JournalEntryFilterValues
  onChange: (value: JournalEntryFilterValues) => void
}

export function JournalEntryFiltersBar({ value, onChange }: JournalEntryFiltersBarProps) {
  const accounts = useChartOfAccountsLookup()
  const branches = useBranchesLookup()

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
        <SearchableSelect
          options={REFERENCE_TYPE_OPTIONS}
          value={value.referenceType ?? undefined}
          onChange={(next) => onChange({ ...value, referenceType: next ?? null })}
          placeholder="All types"
          className="w-36"
          aria-label="Reference Type"
        />
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
        <span className="text-xs text-muted-foreground">Branch</span>
        <SearchableSelect
          options={branches.data?.map((branch) => ({ value: branch.id, label: branch.name })) ?? []}
          value={value.branchId ?? undefined}
          onChange={(next) => onChange({ ...value, branchId: next ?? null })}
          loading={branches.isLoading}
          placeholder="All branches"
          className="w-40"
          aria-label="Branch"
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
