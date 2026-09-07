import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchSuppliersLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import type { DocumentStatus } from '@/features/purchase/types'
import { emptyPurchaseReportFilters, hasActivePurchaseReportFilters } from '../lib/reportFilters'
import type { PurchaseReportFilterValues } from '../types'

const ALL = '__all__'

export type PurchaseReportFilterField = 'supplier' | 'warehouse' | 'status'

interface PurchaseReportFiltersBarProps {
  value: PurchaseReportFilterValues
  onChange: (value: PurchaseReportFilterValues) => void
  /** Fields this tab doesn't use — hidden rather than shown-but-inert, per tab (Purchase Orders has no warehouse; By Supplier/By Item/PO Tracking have no status). */
  hide?: PurchaseReportFilterField[]
}

export function PurchaseReportFiltersBar({ value, onChange, hide = [] }: PurchaseReportFiltersBarProps) {
  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup, enabled: !hide.includes('supplier') })
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: !hide.includes('warehouse') })

  const shown = (field: PurchaseReportFilterField) => !hide.includes(field)

  return (
    <FilterPanel onClear={() => onChange({ ...emptyPurchaseReportFilters, dateFrom: value.dateFrom, dateTo: value.dateTo })} hasActiveFilters={hasActivePurchaseReportFilters(value)}>
      {shown('supplier') && (
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
      )}
      {shown('warehouse') && (
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
      )}
      {shown('status') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Status</span>
          <Select
            value={value.status ?? ALL}
            onValueChange={(next) => onChange({ ...value, status: next === ALL ? null : (next as DocumentStatus) })}
          >
            <SelectTrigger className="w-40">
              <SelectValue placeholder="All statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All statuses</SelectItem>
              <SelectItem value="draft">Draft</SelectItem>
              <SelectItem value="submitted">Submitted</SelectItem>
              <SelectItem value="cancelled">Cancelled</SelectItem>
            </SelectContent>
          </Select>
        </div>
      )}
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">From</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateFrom}
          onChange={(event) => onChange({ ...value, dateFrom: event.target.value })}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input
          type="date"
          className="w-40"
          value={value.dateTo}
          onChange={(event) => onChange({ ...value, dateTo: event.target.value })}
        />
      </div>
    </FilterPanel>
  )
}
