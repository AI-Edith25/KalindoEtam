import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchSuppliersLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import type { SettlementStatus } from '@/features/payment/types'
import { emptyApDetailReportFilters, hasActiveApDetailReportFilters } from '../lib/reportFilters'
import type { AgingBucketValue, ApDetailReportFilterValues } from '../types'

const ALL = '__all__'

interface AccountsPayableDetailReportFiltersBarProps {
  value: ApDetailReportFilterValues
  onChange: (value: ApDetailReportFilterValues) => void
}

/**
 * Own filter set for this report — deliberately not a shared generic filter engine, matching
 * every other report's FiltersBar in this codebase (same rule as AR Detail's own FiltersBar).
 * No Branch/Sales Person filter — neither concept exists anywhere in the Purchase domain.
 * Warehouse takes Branch's place, since it's the real, always-present dimension here.
 */
export function AccountsPayableDetailReportFiltersBar({ value, onChange }: AccountsPayableDetailReportFiltersBarProps) {
  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup })
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyApDetailReportFilters)} hasActiveFilters={hasActiveApDetailReportFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Supplier</span>
        <Select
          value={value.supplier_id || ALL}
          onValueChange={(next) => onChange({ ...value, supplier_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={suppliers.isLoading ? 'Loading…' : 'All suppliers'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All suppliers</SelectItem>
            {suppliers.data?.map((supplier) => (
              <SelectItem key={supplier.id} value={supplier.id}>
                {supplier.supplier_name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Warehouse</span>
        <Select
          value={value.warehouse_id || ALL}
          onValueChange={(next) => onChange({ ...value, warehouse_id: next === ALL ? '' : next })}
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder={warehouses.isLoading ? 'Loading…' : 'All warehouses'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All warehouses</SelectItem>
            {warehouses.data?.map((warehouse) => (
              <SelectItem key={warehouse.id} value={warehouse.id}>
                {warehouse.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Status</span>
        <Select
          value={value.status ?? ALL}
          onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as SettlementStatus) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All statuses</SelectItem>
            <SelectItem value="unpaid">Unpaid</SelectItem>
            <SelectItem value="partially_paid">Partially Paid</SelectItem>
            <SelectItem value="paid">Paid</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Aging</span>
        <Select
          value={value.agingBucket ?? ALL}
          onValueChange={(next) => onChange({ ...value, agingBucket: next === ALL ? null : (next as AgingBucketValue) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All</SelectItem>
            <SelectItem value="30">30 Days</SelectItem>
            <SelectItem value="45">45 Days</SelectItem>
            <SelectItem value="60">60 Days</SelectItem>
            <SelectItem value="90">90 Days</SelectItem>
            <SelectItem value="over_180">Over 180 Days</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Due Date From</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateFrom}
          onChange={(event) => onChange({ ...value, dateFrom: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Due Date To</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateTo}
          onChange={(event) => onChange({ ...value, dateTo: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Invoice Date From</span>
        <Input
          type="date"
          className="w-40"
          value={value.invoiceDateFrom}
          onChange={(event) => onChange({ ...value, invoiceDateFrom: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Invoice Date To</span>
        <Input
          type="date"
          className="w-40"
          value={value.invoiceDateTo}
          onChange={(event) => onChange({ ...value, invoiceDateTo: event.target.value })}
        />
      </div>
    </FilterPanel>
  )
}
