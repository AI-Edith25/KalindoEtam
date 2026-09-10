import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { useBranchesLookup, useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { emptyProfitLossFilters, hasActiveProfitLossFilters } from '../lib/profitLossFilters'
import type { ProfitLossFilterValues, ProfitLossPeriodPreset } from '../types'

interface ProfitLossFiltersBarProps {
  value: ProfitLossFilterValues
  onChange: (value: ProfitLossFilterValues) => void
}

/** Own filter set, not a GeneralLedgerFiltersBar/TrialBalanceFiltersBar variant — no Account Range, no zero-balance toggle, neither applies to a P&L. See docs/PROFIT_LOSS_DESIGN.md §8. */
export function ProfitLossFiltersBar({ value, onChange }: ProfitLossFiltersBarProps) {
  const branches = useBranchesLookup()
  const companies = useCompaniesLookup()

  return (
    <FilterPanel onClear={() => onChange(emptyProfitLossFilters)} hasActiveFilters={hasActiveProfitLossFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Reporting Period</span>
        <Select value={value.periodPreset} onValueChange={(next) => onChange({ ...value, periodPreset: next as ProfitLossPeriodPreset })}>
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="this_month">This Month</SelectItem>
            <SelectItem value="this_quarter">This Quarter</SelectItem>
            <SelectItem value="this_fiscal_year">This Fiscal Year</SelectItem>
            <SelectItem value="custom">Custom Range</SelectItem>
          </SelectContent>
        </Select>
      </div>
      {value.periodPreset === 'custom' && (
        <>
          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-muted-foreground">From</span>
            <Input type="date" className="w-40" value={value.dateFrom} onChange={(event) => onChange({ ...value, dateFrom: event.target.value })} />
          </div>
          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-muted-foreground">To</span>
            <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
          </div>
        </>
      )}
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
