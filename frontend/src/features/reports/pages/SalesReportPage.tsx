import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { ProductSalesPanel } from '../components/ProductSalesPanel'
import { CustomerSalesPanel } from '../components/CustomerSalesPanel'
import { OpenOrdersPanel } from '../components/OpenOrdersPanel'
import { SalesListingPanel } from '../components/SalesListingPanel'
import { emptySalesReportFilters } from '../lib/reportFilters'
import type { SalesReportFilterValues } from '../types'

type SalesReportTab = 'products' | 'customers' | 'open-orders' | 'listing'

const TABS: { value: SalesReportTab; label: string; enabled: boolean }[] = [
  { value: 'products', label: 'Product Sales', enabled: true },
  { value: 'customers', label: 'Customer Sales', enabled: true },
  { value: 'open-orders', label: 'Open Orders', enabled: true },
  { value: 'listing', label: 'Sales Listing', enabled: true },
]

/**
 * Sales Report — 4 tabs (Product Sales, Customer Sales, Open Orders, Sales Listing), each its own
 * server-side aggregate so KPIs always reflect the full filtered set, never just the loaded page
 * (the bug the old single-table page had — see git history). All 4 tabs are built.
 *
 * All URL-synced state (tab, filters, page) is owned here via useSearchParams directly — same
 * reasoning as JournalListPage: this is the only page that needs it, so no shared hook.
 */
export function SalesReportPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const tab = (searchParams.get('tab') as SalesReportTab) || 'products'
  const page = Number(searchParams.get('page') ?? '1')

  const defaults = emptySalesReportFilters()
  const filters: SalesReportFilterValues = {
    customer_id: searchParams.get('customer_id') ?? '',
    item_id: searchParams.get('item_id') ?? '',
    item_group_id: searchParams.get('item_group_id') ?? '',
    sales_person_id: searchParams.get('sales_person_id') ?? '',
    branch_id: searchParams.get('branch_id') ?? '',
    status: searchParams.get('status'),
    dateFrom: searchParams.get('date_from') ?? defaults.dateFrom,
    dateTo: searchParams.get('date_to') ?? defaults.dateTo,
  }

  const update = (patch: Record<string, string | null>) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  // status is cleared on tab change — Product/Customer/Listing filter Invoice's DocumentStatus
  // (draft/submitted/cancelled) while Open Orders filters SalesOrderStatus (submitted/approved
  // only); carrying a value across would 422 against whichever tab doesn't recognize it.
  const setTab = (next: SalesReportTab) => update({ tab: next === 'products' ? null : next, page: null, status: null })
  const setPage = (next: number) => update({ page: next > 1 ? String(next) : null })
  const setFilters = (next: SalesReportFilterValues) =>
    update({
      customer_id: next.customer_id,
      item_id: next.item_id,
      item_group_id: next.item_group_id,
      sales_person_id: next.sales_person_id,
      branch_id: next.branch_id,
      status: next.status,
      date_from: next.dateFrom,
      date_to: next.dateTo,
    })

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader title="Sales Report" description="Product, customer, open-order, and listing views over the same sales data." />

      <div className="flex items-center gap-1 rounded-md border p-1">
        {TABS.map((option) => (
          <Button
            key={option.value}
            size="sm"
            variant={tab === option.value ? 'default' : 'ghost'}
            disabled={!option.enabled}
            onClick={() => setTab(option.value)}
          >
            {option.label}
          </Button>
        ))}
      </div>

      {tab === 'products' && <ProductSalesPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
      {tab === 'customers' && <CustomerSalesPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
      {tab === 'open-orders' && <OpenOrdersPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
      {tab === 'listing' && <SalesListingPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
    </div>
  )
}
