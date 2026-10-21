import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchActiveFleetsLookup } from '@/features/master/api/fleetApi'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'
import { fetchItem } from '@/features/master/api/itemApi'
import { fetchDriverCommissionDrivers } from '../api/driverCommissionReportApi'
import { emptyDriverCommissionReportFilters, hasActiveDriverCommissionReportFilters } from '../lib/reportFilters'
import type { DriverCommissionReportFilterValues, DriverCommissionStatusFilter } from '../types'

const STATUS_OPTIONS: { value: DriverCommissionStatusFilter; label: string }[] = [
  { value: 'included', label: 'Masuk Perhitungan' },
  { value: 'excluded', label: 'Tidak' },
  { value: 'all', label: 'Semua' },
]

interface DriverCommissionReportFiltersBarProps {
  value: DriverCommissionReportFilterValues
  onChange: (value: DriverCommissionReportFilterValues) => void
}

export function DriverCommissionReportFiltersBar({ value, onChange }: DriverCommissionReportFiltersBarProps) {
  const fleets = useQuery({ queryKey: ['fleets-lookup-active'], queryFn: fetchActiveFleetsLookup })
  const drivers = useQuery({ queryKey: ['driver-commission-drivers'], queryFn: fetchDriverCommissionDrivers })

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: item.item_name }))
  }
  const selectedItemQuery = useQuery({
    queryKey: ['item', value.item_id],
    queryFn: () => fetchItem(value.item_id),
    enabled: !!value.item_id,
  })
  const selectedItemOption = selectedItemQuery.data
    ? { value: selectedItemQuery.data.id, label: selectedItemQuery.data.item_name }
    : undefined

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
        <span className="text-xs text-muted-foreground">Sopir</span>
        <SearchableSelect
          options={(drivers.data ?? []).map((driver) => ({ value: driver, label: driver }))}
          value={value.driver || undefined}
          onChange={(next) => onChange({ ...value, driver: next ?? '' })}
          loading={drivers.isLoading}
          placeholder="All drivers"
          aria-label="Sopir"
          className="w-44"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Armada</span>
        <SearchableSelect
          options={(fleets.data ?? []).map((fleet) => ({ value: fleet.id, label: fleet.plat_number }))}
          value={value.fleet_id || undefined}
          onChange={(next) => onChange({ ...value, fleet_id: next ?? '' })}
          loading={fleets.isLoading}
          placeholder="All fleets"
          aria-label="Armada"
          className="w-44"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Jenis Barang</span>
        <SearchableSelect
          loadOptions={loadItemOptions}
          selectedOption={selectedItemOption}
          value={value.item_id || undefined}
          onChange={(next) => onChange({ ...value, item_id: next ?? '' })}
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
