import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchItemGroups } from '@/features/master/api/lookupsApi'
import { useWarehousesLookup } from '@/features/master/hooks/useLookups'
import { emptyStockLedgerFilters, hasActiveStockLedgerFilters } from '../lib/stockLedgerFilters'
import { ItemMultiFilter } from './ItemMultiFilter'
import type { StockLedgerFilterValues, VoucherType } from '../types'

const VOUCHER_TYPE_OPTIONS: SearchableSelectOption<never>[] = [
  { value: 'stock_in', label: 'Stock In' },
  { value: 'goods_receipt', label: 'Goods Receipt' },
  { value: 'delivery', label: 'Delivery' },
  { value: 'stock_adjustment', label: 'Stock Adjustment' },
  { value: 'stock_transfer', label: 'Stock Transfer' },
  { value: 'purchase_return', label: 'Purchase Return' },
  { value: 'credit_note', label: 'Credit Note' },
  { value: 'opening_stock', label: 'Opening Stock' },
  { value: 'issue_stock', label: 'Issue Stock' },
  { value: 'receipt_stock', label: 'Receipt Stock' },
]

interface StockLedgerFiltersBarProps {
  value: StockLedgerFilterValues
  onChange: (value: StockLedgerFilterValues) => void
}

/** Same server-side shape as every other transaction FiltersBar — values are sent straight to the server. */
export function StockLedgerFiltersBar({ value, onChange }: StockLedgerFiltersBarProps) {
  const warehouses = useWarehousesLookup()
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []
  const itemGroups = useQuery({ queryKey: ['item-groups-lookup'], queryFn: fetchItemGroups })
  const itemGroupOptions = itemGroups.data?.map((group) => ({ value: group.id, label: group.name })) ?? []

  return (
    <FilterPanel onClear={() => onChange(emptyStockLedgerFilters)} hasActiveFilters={hasActiveStockLedgerFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Location</span>
        <SearchableSelect
          options={warehouseOptions}
          value={value.warehouse_id || undefined}
          onChange={(next) => onChange({ ...value, warehouse_id: next ?? '' })}
          loading={warehouses.isLoading}
          className="w-44"
          placeholder="All locations"
          aria-label="Location"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Item Group</span>
        <SearchableSelect
          options={itemGroupOptions}
          value={value.item_group_id || undefined}
          onChange={(next) => onChange({ ...value, item_group_id: next ?? '' })}
          loading={itemGroups.isLoading}
          className="w-44"
          placeholder="All item groups"
          aria-label="Item Group"
        />
      </div>
      <ItemMultiFilter value={value.item_id} onChange={(next) => onChange({ ...value, item_id: next })} className="w-44" />
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Voucher Type</span>
        <SearchableSelect
          options={VOUCHER_TYPE_OPTIONS}
          value={value.voucher_type ?? undefined}
          onChange={(next) => onChange({ ...value, voucher_type: (next as VoucherType) ?? null })}
          className="w-40"
          placeholder="All types"
          aria-label="Voucher Type"
        />
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
