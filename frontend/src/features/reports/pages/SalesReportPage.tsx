import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Upload } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { fetchSalesArchiveMeta } from '../api/salesArchiveApi'
import { ProductSalesPanel } from '../components/ProductSalesPanel'
import { CustomerSalesPanel } from '../components/CustomerSalesPanel'
import { OpenOrdersPanel } from '../components/OpenOrdersPanel'
import { SalesListingPanel } from '../components/SalesListingPanel'
import { SalesArchiveImportDialog } from '../components/SalesArchiveImportDialog'
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
 * (the bug the old single-table page had — see git history). The former 5th tab, Margin, split out
 * into its own standalone "Gross Profit" report (2026-09-19) — see GrossProfitReportPage.tsx.
 *
 * All URL-synced state (tab, filters, page) is owned here via useSearchParams directly — same
 * reasoning as JournalListPage: this is the only page that needs it, so no shared hook.
 */
export function SalesReportPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [importOpen, setImportOpen] = useState(false)
  const canImportArchive = useHasPermission('reports.sales_archive.import')

  // Fetched once here, passed down to the 3 tabs that can fall back to it -- Open Orders never
  // touches the archive at all (no import can produce open-order data, see the ticket).
  const archiveMetaQuery = useQuery({ queryKey: ['sales-archive-meta'], queryFn: fetchSalesArchiveMeta })
  const archiveMeta = archiveMetaQuery.data

  const tab = (searchParams.get('tab') as SalesReportTab) || 'products'
  const page = Number(searchParams.get('page') ?? '1')

  const defaults = emptySalesReportFilters()
  const filters: SalesReportFilterValues = {
    customer_ids: searchParams.getAll('customer_ids'),
    item_ids: searchParams.getAll('item_ids'),
    item_group_id: searchParams.get('item_group_id') ?? '',
    sales_person_ids: searchParams.getAll('sales_person_ids'),
    branch_ids: searchParams.getAll('branch_ids'),
    status: searchParams.get('status'),
    dateFrom: searchParams.get('date_from') ?? defaults.dateFrom,
    dateTo: searchParams.get('date_to') ?? defaults.dateTo,
  }

  const update = (patch: Record<string, string | string[] | null>) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        next.delete(key)
        if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) continue
        if (Array.isArray(value)) value.forEach((v) => next.append(key, v))
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
  // page reset folded into this same update() call, not a separate setPage(1) — two
  // setSearchParams() calls in the same handler race each other (react-router's setter reads
  // location.search fresh each call rather than chaining off the first call's pending result),
  // so the second call was silently clobbering the first's filter change back to empty.
  const setFilters = (next: SalesReportFilterValues) =>
    update({
      customer_ids: next.customer_ids,
      item_ids: next.item_ids,
      item_group_id: next.item_group_id,
      sales_person_ids: next.sales_person_ids,
      branch_ids: next.branch_ids,
      status: next.status,
      date_from: next.dateFrom,
      date_to: next.dateTo,
      page: null,
    })

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Sales Report"
        description="Product, customer, open-order, and listing views over the same sales data."
        actions={
          tab !== 'open-orders' && canImportArchive ? (
            <ActionBar actions={[]} primary={{ label: 'Import Data', icon: Upload, onClick: () => setImportOpen(true) }} />
          ) : undefined
        }
      />

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

      {tab === 'products' && <ProductSalesPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} archiveMeta={archiveMeta} />}
      {tab === 'customers' && <CustomerSalesPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} archiveMeta={archiveMeta} />}
      {tab === 'open-orders' && <OpenOrdersPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
      {tab === 'listing' && <SalesListingPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} archiveMeta={archiveMeta} />}

      <SalesArchiveImportDialog open={importOpen} onClose={() => setImportOpen(false)} onImported={() => archiveMetaQuery.refetch()} />
    </div>
  )
}
