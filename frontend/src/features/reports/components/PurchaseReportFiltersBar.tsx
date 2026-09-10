import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchSupplier } from '@/features/master/api/supplierApi'
import { fetchWarehousesLookup, searchSuppliersLookup } from '@/features/master/api/lookupsApi'
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
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: !hide.includes('warehouse') })

  const shown = (field: PurchaseReportFilterField) => !hide.includes(field)

  const loadSupplierOptions = async (query: string) => {
    const suppliers = await searchSuppliersLookup(query)
    return suppliers.map((supplier) => ({ value: supplier.id, label: supplier.supplier_name }))
  }
  const selectedSupplierQuery = useQuery({
    queryKey: ['supplier', value.supplier_id],
    queryFn: () => fetchSupplier(value.supplier_id),
    enabled: shown('supplier') && !!value.supplier_id,
  })
  const selectedSupplierOption = selectedSupplierQuery.data
    ? { value: selectedSupplierQuery.data.id, label: selectedSupplierQuery.data.supplier_name }
    : undefined

  return (
    <FilterPanel onClear={() => onChange({ ...emptyPurchaseReportFilters, dateFrom: value.dateFrom, dateTo: value.dateTo })} hasActiveFilters={hasActivePurchaseReportFilters(value)}>
      {shown('supplier') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Supplier</span>
          <SearchableSelect
            loadOptions={loadSupplierOptions}
            selectedOption={selectedSupplierOption}
            value={value.supplier_id || undefined}
            onChange={(next) => onChange({ ...value, supplier_id: next ?? '' })}
            placeholder="All suppliers"
            aria-label="Supplier"
            className="w-44"
          />
        </div>
      )}
      {shown('warehouse') && (
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Warehouse</span>
          <SearchableSelect
            options={warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []}
            value={value.warehouse_id || undefined}
            onChange={(next) => onChange({ ...value, warehouse_id: next ?? '' })}
            loading={warehouses.isLoading}
            placeholder="All warehouses"
            aria-label="Warehouse"
            className="w-44"
          />
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
