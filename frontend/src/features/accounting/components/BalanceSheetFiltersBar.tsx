import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { useBranchesLookup, useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { emptyBalanceSheetFilters, hasActiveBalanceSheetFilters } from '../lib/balanceSheetFilters'
import type { BalanceSheetFilterValues } from '../types'

interface BalanceSheetFiltersBarProps {
  value: BalanceSheetFilterValues
  onChange: (value: BalanceSheetFilterValues) => void
}

/** Simplest filter bar in Accounting Reports — a single As Of Date, no period preset (a snapshot, not a range). See docs/BALANCE_SHEET_DESIGN.md §7/§9. */
export function BalanceSheetFiltersBar({ value, onChange }: BalanceSheetFiltersBarProps) {
  const branches = useBranchesLookup()
  const companies = useCompaniesLookup()

  return (
    <FilterPanel onClear={() => onChange(emptyBalanceSheetFilters)} hasActiveFilters={hasActiveBalanceSheetFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">As Of Date</span>
        <Input type="date" className="w-40" value={value.asOfDate} onChange={(event) => onChange({ ...value, asOfDate: event.target.value })} />
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
        <span className="text-xs text-muted-foreground">Company</span>
        <SearchableSelect
          options={companies.data?.map((company) => ({ value: company.id, label: company.name })) ?? []}
          value={value.companyId ?? undefined}
          onChange={(next) => onChange({ ...value, companyId: next ?? null })}
          loading={companies.isLoading}
          placeholder="All companies"
          className="w-40"
          aria-label="Company"
        />
      </div>
    </FilterPanel>
  )
}
