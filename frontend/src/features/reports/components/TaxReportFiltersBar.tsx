import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { fetchCustomer } from '@/features/master/api/customerApi'
import { fetchSupplier } from '@/features/master/api/supplierApi'
import { fetchBranches, fetchTaxesLookup, fetchWarehousesLookup, searchCustomersLookup, searchSuppliersLookup } from '@/features/master/api/lookupsApi'
import { currentMonthTaxReportFilters } from '../lib/reportFilters'
import type { TaxReportFilterValues } from '../types'

interface TaxReportFiltersBarProps {
  value: TaxReportFilterValues
  onChange: (value: TaxReportFilterValues) => void
  /** PPN Keluaran (Sales, has a real tax-code trail + Branch) vs PPN Masukan (Purchase, no tax-code trail anywhere in its schema, Warehouse instead of Branch — same finding as AP Detail). */
  mode: 'output' | 'input'
}

/** Own filter set for this report — deliberately not a shared generic filter engine, matching every other report's FiltersBar in this codebase. */
export function TaxReportFiltersBar({ value, onChange, mode }: TaxReportFiltersBarProps) {
  const taxes = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup, enabled: mode === 'output' })
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches, enabled: mode === 'output' })
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: mode === 'input' })

  const loadCustomerOptions = async (query: string) => {
    const customers = await searchCustomersLookup(query)
    return customers.map((customer) => ({ value: customer.id, label: customer.customer_name }))
  }
  const selectedCustomerQuery = useQuery({
    queryKey: ['customer', value.customer_id],
    queryFn: () => fetchCustomer(value.customer_id),
    enabled: mode === 'output' && !!value.customer_id,
  })
  const selectedCustomerOption = selectedCustomerQuery.data
    ? { value: selectedCustomerQuery.data.id, label: selectedCustomerQuery.data.customer_name }
    : undefined

  const loadSupplierOptions = async (query: string) => {
    const suppliers = await searchSuppliersLookup(query)
    return suppliers.map((supplier) => ({ value: supplier.id, label: supplier.supplier_name }))
  }
  const selectedSupplierQuery = useQuery({
    queryKey: ['supplier', value.supplier_id],
    queryFn: () => fetchSupplier(value.supplier_id),
    enabled: mode === 'input' && !!value.supplier_id,
  })
  const selectedSupplierOption = selectedSupplierQuery.data
    ? { value: selectedSupplierQuery.data.id, label: selectedSupplierQuery.data.supplier_name }
    : undefined

  // Single "Bulan" control writing both dateFrom/dateTo at once — this is explicitly a monthly
  // report ("Filter bulan harus mudah" in the ticket) — the existing From/To pair below it still
  // works for a manual/cross-month range.
  const monthValue = value.dateFrom.slice(0, 7)
  const setMonth = (month: string) => {
    if (!month) return
    const [year, m] = month.split('-').map(Number)
    const from = `${month}-01`
    const to = new Date(year, m, 0).toISOString().slice(0, 10)
    onChange({ ...value, dateFrom: from, dateTo: to })
  }

  // Date range is excluded here — it's always populated (defaults to the current month), so
  // treating it as an "active filter" would make the Clear affordance permanently show/no-op.
  const hasActiveFilters = !!(value.tax_id || value.customer_id || value.branch_id || value.supplier_id || value.warehouse_id)

  return (
    <FilterPanel onClear={() => onChange(currentMonthTaxReportFilters())} hasActiveFilters={hasActiveFilters}>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">Bulan</span>
        <Input type="month" className="w-40" value={monthValue} onChange={(event) => setMonth(event.target.value)} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">From</span>
        <Input type="date" className="w-40" value={value.dateFrom} onChange={(event) => onChange({ ...value, dateFrom: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-muted-foreground">To</span>
        <Input type="date" className="w-40" value={value.dateTo} onChange={(event) => onChange({ ...value, dateTo: event.target.value })} />
      </div>
      {mode === 'output' && (
        <>
          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-muted-foreground">Kode Pajak</span>
            <SearchableSelect
              options={taxes.data?.map((tax) => ({ value: tax.id, label: tax.code })) ?? []}
              value={value.tax_id || undefined}
              onChange={(next) => onChange({ ...value, tax_id: next ?? '' })}
              loading={taxes.isLoading}
              placeholder="All"
              aria-label="Kode Pajak"
              className="w-40"
            />
          </div>
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
            <span className="text-xs text-muted-foreground">Branch</span>
            <SearchableSelect
              options={branches.data?.map((branch) => ({ value: branch.id, label: branch.name })) ?? []}
              value={value.branch_id || undefined}
              onChange={(next) => onChange({ ...value, branch_id: next ?? '' })}
              loading={branches.isLoading}
              placeholder="All branches"
              aria-label="Branch"
              className="w-44"
            />
          </div>
        </>
      )}
      {mode === 'input' && (
        <>
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
        </>
      )}
    </FilterPanel>
  )
}
