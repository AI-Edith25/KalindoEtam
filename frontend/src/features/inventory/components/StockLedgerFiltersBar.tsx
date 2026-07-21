import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchItemsLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { emptyStockLedgerFilters, hasActiveStockLedgerFilters } from '../lib/stockLedgerFilters'
import type { StockLedgerFilterValues, VoucherType } from '../types'

const ALL = '__all__'

interface StockLedgerFiltersBarProps {
  value: StockLedgerFilterValues
  onChange: (value: StockLedgerFilterValues) => void
}

/** Same server-side shape as every other transaction FiltersBar — values are sent straight to the server. */
export function StockLedgerFiltersBar({ value, onChange }: StockLedgerFiltersBarProps) {
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup })

  return (
    <FilterPanel onClear={() => onChange(emptyStockLedgerFilters)} hasActiveFilters={hasActiveStockLedgerFilters(value)}>
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
        <span className="text-xs text-muted-foreground">Item</span>
        <Select value={value.item_id || ALL} onValueChange={(next) => onChange({ ...value, item_id: next === ALL ? '' : next })}>
          <SelectTrigger className="w-44">
            <SelectValue placeholder={items.isLoading ? 'Loading…' : 'All items'} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All items</SelectItem>
            {items.data?.map((item) => (
              <SelectItem key={item.id} value={item.id}>
                {item.item_code} — {item.item_name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Voucher Type</span>
        <Select
          value={value.voucher_type ?? ALL}
          onValueChange={(next) => onChange({ ...value, voucher_type: next === ALL ? null : (next as VoucherType) })}
        >
          <SelectTrigger className="w-40">
            <SelectValue placeholder="All types" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="stock_in">Stock In</SelectItem>
            <SelectItem value="goods_receipt">Goods Receipt</SelectItem>
            <SelectItem value="delivery">Delivery</SelectItem>
            <SelectItem value="stock_adjustment">Stock Adjustment</SelectItem>
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
