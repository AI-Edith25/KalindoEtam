import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchCustomer } from '@/features/master/api/customerApi'
import { fetchItem } from '@/features/master/api/itemApi'
import { fetchWarehousesLookup, searchCustomersLookup, searchItemsLookup } from '@/features/master/api/lookupsApi'
import { emptyDeliveryReportFilters, hasActiveDeliveryReportFilters } from '../lib/reportFilters'
import type { DeliveryReportFilterValues } from '../types'

interface DeliveryReportFiltersBarProps {
  value: DeliveryReportFilterValues
  onChange: (value: DeliveryReportFilterValues) => void
}

export function DeliveryReportFiltersBar({ value, onChange }: DeliveryReportFiltersBarProps) {
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })

  const loadCustomerOptions = async (query: string) => {
    const customers = await searchCustomersLookup(query)
    return customers.map((customer) => ({ value: customer.id, label: customer.customer_name }))
  }
  const selectedCustomerQuery = useQuery({
    queryKey: ['customer', value.customer_id],
    queryFn: () => fetchCustomer(value.customer_id),
    enabled: !!value.customer_id,
  })
  const selectedCustomerOption = selectedCustomerQuery.data
    ? { value: selectedCustomerQuery.data.id, label: selectedCustomerQuery.data.customer_name }
    : undefined

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
    <FilterPanel onClear={() => onChange(emptyDeliveryReportFilters)} hasActiveFilters={hasActiveDeliveryReportFilters(value)}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Customer</span>
        <SearchableSelect
          loadOptions={loadCustomerOptions}
          selectedOption={selectedCustomerOption}
          value={value.customer_id || undefined}
          onChange={(next) => onChange({ ...value, customer_id: next ?? '' })}
          placeholder="All customers"
          aria-label="Customer"
          className="w-44"
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Item</span>
        <SearchableSelect
          loadOptions={loadItemOptions}
          selectedOption={selectedItemOption}
          value={value.item_id || undefined}
          onChange={(next) => onChange({ ...value, item_id: next ?? '' })}
          placeholder="All items"
          aria-label="Item"
          className="w-44"
        />
      </div>
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
