import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
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
        <Select value={value.branchId ?? ALL} onValueChange={(next) => onChange({ ...value, branchId: next === ALL ? null : next })}>
          <SelectTrigger className="w-40">
            <SelectValue placeholder={branches.isLoading ? 'Loading…' : 'All branches'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All branches</SelectItem>
            {branches.data?.map((branch) => (
              <SelectItem key={branch.id} value={branch.id}>
                {branch.name}
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
