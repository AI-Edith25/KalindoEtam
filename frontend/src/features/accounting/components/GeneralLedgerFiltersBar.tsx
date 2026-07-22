import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { useBranchesLookup, useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { emptyGeneralLedgerFilters, hasActiveGeneralLedgerFilters } from '../lib/generalLedgerFilters'
import type { DocumentStatus, GeneralLedgerFilterValues } from '../types'

const ALL = '__all__'

interface GeneralLedgerFiltersBarProps {
  value: GeneralLedgerFilterValues
  onChange: (value: GeneralLedgerFilterValues) => void
  /**
   * 'list' (Ledger List): Company shown, Reference Number omitted — Account
   * is the row, not a filter. 'detail' (Account Drill-down): Reference
   * Number shown, Company omitted — a single account's lines are already
   * branch/company-scoped by definition. See docs/GENERAL_LEDGER_DESIGN.md §5.
   */
  variant: 'list' | 'detail'
}

/** Branch/Company filter dormant until a business module populates journal_entry_lines.branch_id — see docs/GENERAL_LEDGER_DESIGN.md §0/§4. */
export function GeneralLedgerFiltersBar({ value, onChange, variant }: GeneralLedgerFiltersBarProps) {
  const branches = useBranchesLookup()
  const companies = useCompaniesLookup(variant === 'list')

  return (
    <FilterPanel onClear={() => onChange(emptyGeneralLedgerFilters)} hasActiveFilters={hasActiveGeneralLedgerFilters(value)}>
      {variant === 'detail' && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Reference Number</span>
          <Input
            className="w-44"
            placeholder="e.g. INV-00001"
            value={value.referenceNumber}
            onChange={(event) => onChange({ ...value, referenceNumber: event.target.value })}
          />
        </div>
      )}
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Reference Type</span>
        <Select
          value={value.referenceType ?? ALL}
          onValueChange={(next) => onChange({ ...value, referenceType: next === ALL ? null : next })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All types" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="invoice">Invoice</SelectItem>
            <SelectItem value="credit_note">Credit Note</SelectItem>
            <SelectItem value="debit_note">Debit Note</SelectItem>
            <SelectItem value="receipt_entry">Receipt Entry</SelectItem>
            <SelectItem value="payment_allocation">Payment Allocation</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.status ?? ALL}
          onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as DocumentStatus) })}
        >
          <SelectTrigger className="w-36">
            <SelectValue placeholder="Posted" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All statuses</SelectItem>
            <SelectItem value="draft">Draft</SelectItem>
            <SelectItem value="submitted">Posted</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Branch</span>
        <Select
          value={value.branchId ?? ALL}
          onValueChange={(next) => onChange({ ...value, branchId: next === ALL ? null : next })}
        >
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
      {variant === 'list' && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Company</span>
          <Select
            value={value.companyId ?? ALL}
            onValueChange={(next) => onChange({ ...value, companyId: next === ALL ? null : next })}
          >
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
      )}
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
