import { FilterPanel } from '@/components/shared/FilterPanel'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { AuditLogFilterValues } from '../types'

const ALL_MODULES = '__all__'

const MODULES = ['auth', 'user', 'role', 'company', 'tax', 'invoice', 'purchase_order']

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
        <Select value={value.module ?? ALL_MODULES} onValueChange={(module) => onChange({ ...value, module: module === ALL_MODULES ? undefined : module })}>
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All modules" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_MODULES}>All modules</SelectItem>
            {MODULES.map((module) => (
              <SelectItem key={module} value={module}>
                {module}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
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
