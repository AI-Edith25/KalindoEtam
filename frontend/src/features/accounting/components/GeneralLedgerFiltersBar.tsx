import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { useBranchesLookup, useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { emptyGeneralLedgerFilters, hasActiveGeneralLedgerFilters } from '../lib/generalLedgerFilters'
import type { DocumentStatus, GeneralLedgerFilterValues } from '../types'

const ALL = '__all__'

const REFERENCE_TYPE_OPTIONS = [
  { value: 'invoice', label: 'Invoice' },
  { value: 'credit_note', label: 'Credit Note' },
  { value: 'debit_note', label: 'Debit Note' },
  { value: 'receipt_entry', label: 'Receipt Entry' },
  { value: 'payment_allocation', label: 'Payment Allocation' },
]

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
        <SearchableSelect
          options={REFERENCE_TYPE_OPTIONS}
          value={value.referenceType ?? undefined}
          onChange={(next) => onChange({ ...value, referenceType: next ?? null })}
          placeholder="All types"
          className="w-40"
          aria-label="Reference Type"
        />
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
      {variant === 'list' && (
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
