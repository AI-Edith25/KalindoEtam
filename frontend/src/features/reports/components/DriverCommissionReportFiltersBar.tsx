import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchActiveFleetsLookup, formatFleetLabel } from '@/features/master/api/fleetApi'
import { DELIVERY_METHOD_OPTIONS } from '@/features/sales/lib/deliveryMethod'
import { fetchDriverCommissionCommissionableItems } from '../api/driverCommissionReportApi'
import { emptyDriverCommissionReportFilters, hasActiveDriverCommissionReportFilters } from '../lib/reportFilters'
import type { DriverCommissionReportFilterValues, DriverCommissionStatusFilter } from '../types'

const STATUS_OPTIONS: { value: DriverCommissionStatusFilter; label: string }[] = [{ value: 'all', label: 'Semua' }, ...DELIVERY_METHOD_OPTIONS]

interface DriverCommissionReportFiltersBarProps {
  value: DriverCommissionReportFilterValues
  onChange: (value: DriverCommissionReportFilterValues) => void
}

export function DriverCommissionReportFiltersBar({ value, onChange }: DriverCommissionReportFiltersBarProps) {
  const fleets = useQuery({ queryKey: ['fleets-lookup-active'], queryFn: fetchActiveFleetsLookup })
  const commissionableItems = useQuery({ queryKey: ['driver-commission-items'], queryFn: fetchDriverCommissionCommissionableItems })

  return (
    <FilterPanel onClear={() => onChange(emptyDriverCommissionReportFilters)} hasActiveFilters={hasActiveDriverCommissionReportFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">From</span>
        <Input type="date" className="w-40" value={value.dateFrom} onChange={(event) => onChange({ ...value, dateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Driver</span>
        <SearchableSelect
          options={(fleets.data ?? []).map((fleet) => ({ value: fleet.id, label: formatFleetLabel(fleet) }))}
          value={value.fleet_id || undefined}
          onChange={(next) => onChange({ ...value, fleet_id: next ?? '' })}
          loading={fleets.isLoading}
          placeholder="All drivers"
          aria-label="Driver"
          className="w-44"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Jenis Barang</span>
        <SearchableSelect
          options={(commissionableItems.data ?? []).map((item) => ({ value: item.id, label: item.item_name }))}
          value={value.item_id || undefined}
          onChange={(next) => onChange({ ...value, item_id: next ?? '' })}
          loading={commissionableItems.isLoading}
          placeholder="All items"
          aria-label="Jenis Barang"
          className="w-44"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select value={value.status} onValueChange={(next) => onChange({ ...value, status: next as DriverCommissionStatusFilter })}>
          <SelectTrigger className="w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {STATUS_OPTIONS.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </FilterPanel>
  )
}
