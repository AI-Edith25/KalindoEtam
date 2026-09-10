import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { useBranchesLookup } from '@/features/master/hooks/useLookups'
import type { SalesJournalFilterValues } from '../types'

interface SalesJournalFiltersBarProps {
  value: SalesJournalFilterValues
  onChange: (value: SalesJournalFilterValues) => void
}

export function SalesJournalFiltersBar({ value, onChange }: SalesJournalFiltersBarProps) {
  const branches = useBranchesLookup()
  const hasActiveFilters = value.branchId !== null || value.dateFrom !== '' || value.dateTo !== ''

  return (
    <FilterPanel onClear={() => onChange({ branchId: null, dateFrom: '', dateTo: '' })} hasActiveFilters={hasActiveFilters}>
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
        <Input type="date" className="w-40" value={value.dateFrom} onChange={(event) => onChange({ ...value, dateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
      </div>
    </FilterPanel>
  )
}
