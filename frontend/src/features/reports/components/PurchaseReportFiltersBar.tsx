import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchSuppliersLookup } from '@/features/master/api/lookupsApi'
import type { DocumentStatus } from '@/features/purchase/types'
import { emptyPurchaseReportFilters, hasActivePurchaseReportFilters } from '../lib/reportFilters'
import type { PurchaseReportFilterValues } from '../types'

const ALL = '__all__'

interface PurchaseReportFiltersBarProps {
  value: PurchaseReportFilterValues
  onChange: (value: PurchaseReportFilterValues) => void
}

export function PurchaseReportFiltersBar({ value, onChange }: PurchaseReportFiltersBarProps) {
  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyPurchaseReportFilters)} hasActiveFilters={hasActivePurchaseReportFilters(value)}>
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
