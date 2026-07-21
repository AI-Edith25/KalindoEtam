import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { fetchBranches, fetchCompaniesLookup } from '@/features/master/api/lookupsApi'
import { emptyTrialBalanceFilters, hasActiveTrialBalanceFilters } from '../lib/trialBalanceFilters'
import type { TrialBalanceFilterValues, TrialBalancePeriodPreset } from '../types'

const ALL = '__all__'

interface TrialBalanceFiltersBarProps {
  value: TrialBalanceFilterValues
  onChange: (value: TrialBalanceFilterValues) => void
}

/** Own filter set — deliberately not a third GeneralLedgerFiltersBar variant, see docs/TRIAL_BALANCE_DESIGN.md §5. */
export function TrialBalanceFiltersBar({ value, onChange }: TrialBalanceFiltersBarProps) {
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })
  const companies = useQuery({ queryKey: ['companies-lookup'], queryFn: fetchCompaniesLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyTrialBalanceFilters)} hasActiveFilters={hasActiveTrialBalanceFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Reporting Period</span>
        <Select value={value.periodPreset} onValueChange={(next) => onChange({ ...value, periodPreset: next as TrialBalancePeriodPreset })}>
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
        <span className="text-xs text-muted-foreground">Account Range</span>
        <div className="flex items-center gap-1.5">
          <Input
            className="w-20"
            placeholder="From"
            value={value.codeFrom}
            onChange={(event) => onChange({ ...value, codeFrom: event.target.value })}
          />
          <span className="text-muted-foreground">–</span>
          <Input className="w-20" placeholder="To" value={value.codeTo} onChange={(event) => onChange({ ...value, codeTo: event.target.value })} />
        </div>
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
      <div className="flex items-center gap-2 rounded-md border p-2">
        <Switch
          id="hide-zero-balance"
          checked={value.hideZeroBalance}
          onCheckedChange={(checked) => onChange({ ...value, hideZeroBalance: checked })}
        />
        <Label htmlFor="hide-zero-balance" className="cursor-pointer text-sm font-normal">
          Hide zero-balance accounts
        </Label>
      </div>
    </FilterPanel>
  )
}
