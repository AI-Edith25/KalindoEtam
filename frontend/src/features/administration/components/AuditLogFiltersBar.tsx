import { FilterPanel } from '@/components/shared/FilterPanel'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import type { AuditLogFilterValues } from '../types'

const MODULES = ['auth', 'user', 'role', 'company', 'tax', 'invoice', 'purchase_order']

const MODULE_OPTIONS = MODULES.map((module) => ({ value: module, label: module }))

interface AuditLogFiltersBarProps {
  value: AuditLogFilterValues
  onChange: (value: AuditLogFilterValues) => void
}

export function AuditLogFiltersBar({ value, onChange }: AuditLogFiltersBarProps) {
  const hasActiveFilters = !!(value.module || value.date_from || value.date_to)

  return (
    <FilterPanel onClear={() => onChange({})} hasActiveFilters={hasActiveFilters}>
      <div className="flex flex-col gap-1.5">
        <Label>Module</Label>
        <SearchableSelect
          options={MODULE_OPTIONS}
          value={value.module ?? undefined}
          onChange={(module) => onChange({ ...value, module })}
          placeholder="All modules"
          className="w-40"
          aria-label="Module"
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <Label>From</Label>
        <Input type="date" value={value.date_from ?? ''} onChange={(event) => onChange({ ...value, date_from: event.target.value || undefined })} />
      </div>

      <div className="flex flex-col gap-1.5">
        <Label>To</Label>
        <Input type="date" value={value.date_to ?? ''} onChange={(event) => onChange({ ...value, date_to: event.target.value || undefined })} />
      </div>
    </FilterPanel>
  )
}
