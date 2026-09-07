import { useQuery } from '@tanstack/react-query'
import { FilterPanel } from '@/components/shared/FilterPanel'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { fetchBranches, fetchCustomersLookup, fetchSuppliersLookup, fetchTaxesLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { currentMonthTaxReportFilters } from '../lib/reportFilters'
import type { TaxReportFilterValues } from '../types'

const ALL = '__all__'

interface TaxReportFiltersBarProps {
  value: TaxReportFilterValues
  onChange: (value: TaxReportFilterValues) => void
  /** PPN Keluaran (Sales, has a real tax-code trail + Branch) vs PPN Masukan (Purchase, no tax-code trail anywhere in its schema, Warehouse instead of Branch — same finding as AP Detail). */
  mode: 'output' | 'input'
}

/** Own filter set for this report — deliberately not a shared generic filter engine, matching every other report's FiltersBar in this codebase. */
export function TaxReportFiltersBar({ value, onChange, mode }: TaxReportFiltersBarProps) {
  const taxes = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup, enabled: mode === 'output' })
  const customers = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup, enabled: mode === 'output' })
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches, enabled: mode === 'output' })
  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup, enabled: mode === 'input' })
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: mode === 'input' })

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
            <Select value={value.tax_id || ALL} onValueChange={(next) => onChange({ ...value, tax_id: next === ALL ? '' : next })}>
              <SelectTrigger className="w-40">
                <SelectValue placeholder={taxes.isLoading ? 'Loading…' : 'All'} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>All</SelectItem>
                {taxes.data?.map((tax) => (
                  <SelectItem key={tax.id} value={tax.id}>
                    {tax.code}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-muted-foreground">Customer</span>
            <Select value={value.customer_id || ALL} onValueChange={(next) => onChange({ ...value, customer_id: next === ALL ? '' : next })}>
              <SelectTrigger className="w-44">
                <SelectValue placeholder={customers.isLoading ? 'Loading…' : 'All customers'} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>All customers</SelectItem>
                {customers.data?.map((customer) => (
                  <SelectItem key={customer.id} value={customer.id}>
                    {customer.customer_name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-muted-foreground">Branch</span>
            <Select value={value.branch_id || ALL} onValueChange={(next) => onChange({ ...value, branch_id: next === ALL ? '' : next })}>
              <SelectTrigger className="w-44">
                <SelectValue placeholder={branches.isLoading ? 'Loading…' : 'All branches'} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>All branches</SelectItem>
                {branches.data?.map((branch) => (
                  <SelectItem key={branch.id} value={branch.id}>
                    {branch.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </>
      )}
      {mode === 'input' && (
        <>
          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-muted-foreground">Supplier</span>
            <Select value={value.supplier_id || ALL} onValueChange={(next) => onChange({ ...value, supplier_id: next === ALL ? '' : next })}>
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
            <Select value={value.warehouse_id || ALL} onValueChange={(next) => onChange({ ...value, warehouse_id: next === ALL ? '' : next })}>
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
        </>
      )}
    </FilterPanel>
  )
}
