import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchBranches, fetchCompaniesLookup } from '@/features/master/api/lookupsApi'
import { emptyProfitLossFilters, hasActiveProfitLossFilters } from '../lib/profitLossFilters'
import type { ProfitLossFilterValues, ProfitLossPeriodPreset } from '../types'

const ALL = '__all__'

interface ProfitLossFiltersBarProps {
  value: ProfitLossFilterValues
  onChange: (value: ProfitLossFilterValues) => void
}

/** Own filter set, not a GeneralLedgerFiltersBar/TrialBalanceFiltersBar variant — no Account Range, no zero-balance toggle, neither applies to a P&L. See docs/PROFIT_LOSS_DESIGN.md §8. */
export function ProfitLossFiltersBar({ value, onChange }: ProfitLossFiltersBarProps) {
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })
  const companies = useQuery({ queryKey: ['companies-lookup'], queryFn: fetchCompaniesLookup })

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
