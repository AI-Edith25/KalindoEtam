import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchBranches, fetchCompaniesLookup } from '@/features/master/api/lookupsApi'
import { emptyBalanceSheetFilters, hasActiveBalanceSheetFilters } from '../lib/balanceSheetFilters'
import type { BalanceSheetFilterValues } from '../types'

const ALL = '__all__'

interface BalanceSheetFiltersBarProps {
  value: BalanceSheetFilterValues
  onChange: (value: BalanceSheetFilterValues) => void
}

/** Simplest filter bar in Accounting Reports — a single As Of Date, no period preset (a snapshot, not a range). See docs/BALANCE_SHEET_DESIGN.md §7/§9. */
export function BalanceSheetFiltersBar({ value, onChange }: BalanceSheetFiltersBarProps) {
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })
  const companies = useQuery({ queryKey: ['companies-lookup'], queryFn: fetchCompaniesLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyBalanceSheetFilters)} hasActiveFilters={hasActiveBalanceSheetFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">As Of Date</span>
        <Input type="date" className="w-40" value={value.asOfDate} onChange={(event) => onChange({ ...value, asOfDate: event.target.value })} />
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
        <span className="text-xs text-muted-foreground">Company</span>
        <Select value={value.companyId ?? ALL} onValueChange={(next) => onChange({ ...value, companyId: next === ALL ? null : next })}>
          <SelectTrigger className="w-40">
            <SelectValue placeholder={companies.isLoading ? 'Loading…' : 'All companies'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All companies</SelectItem>
            {companies.data?.map((company) => (
              <SelectItem key={company.id} value={company.id}>
                {company.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
