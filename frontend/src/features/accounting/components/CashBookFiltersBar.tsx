import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { useBranchesLookup } from '@/features/master/hooks/useLookups'
import { emptyCashBookFilters, hasActiveCashBookFilters } from '../lib/cashBookFilters'
import type { CashBookFilterValues } from '../types'

const ALL = '__all__'

interface CashBookFiltersBarProps {
  value: CashBookFilterValues
  onChange: (value: CashBookFilterValues) => void
}

export function CashBookFiltersBar({ value, onChange }: CashBookFiltersBarProps) {
  const branches = useBranchesLookup()

  return (
    <FilterPanel onClear={() => onChange(emptyCashBookFilters)} hasActiveFilters={hasActiveCashBookFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select value={value.status ?? ALL} onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : next })}>
          <SelectTrigger className="w-36">
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
