import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { PurchaseOrdersPanel } from '../components/PurchaseOrdersPanel'
import { PurchaseBySupplierPanel } from '../components/PurchaseBySupplierPanel'
import { PurchaseByItemPanel } from '../components/PurchaseByItemPanel'
import { PoTrackingPanel } from '../components/PoTrackingPanel'
import { currentMonthPurchaseReportFilters, emptyPurchaseReportFilters } from '../lib/reportFilters'
import type { PurchaseReportFilterValues } from '../types'

type PurchaseReportTab = 'orders' | 'by-supplier' | 'by-item' | 'po-tracking'

const TABS: { value: PurchaseReportTab; label: string }[] = [
  { value: 'orders', label: 'Purchase Orders' },
  { value: 'by-supplier', label: 'By Supplier' },
  { value: 'by-item', label: 'By Item' },
  { value: 'po-tracking', label: 'PO Tracking' },
]

/**
 * Purchase Report — 4 tabs, same URL-synced-state shape as SalesReportPage. Purchase Orders keeps
 * today's behavior (no default date range); By Supplier/By Item/PO Tracking default to "current
 * month" and are sourced from Goods Receipt, never Purchase Order — see each panel's own docblock.
 */
export function PurchaseReportPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const tab = (searchParams.get('tab') as PurchaseReportTab) || 'orders'
  const page = Number(searchParams.get('page') ?? '1')

  const defaults = tab === 'orders' ? emptyPurchaseReportFilters : currentMonthPurchaseReportFilters()
  const filters: PurchaseReportFilterValues = {
    supplier_id: searchParams.get('supplier_id') ?? '',
    warehouse_id: searchParams.get('warehouse_id') ?? '',
    status: searchParams.get('status') as PurchaseReportFilterValues['status'],
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

  // Switching tabs also clears date_from/date_to, so each tab's own default range (empty for
  // Purchase Orders, current-month for the other 3) applies fresh rather than carrying over.
  const setTab = (next: PurchaseReportTab) => update({ tab: next === 'orders' ? null : next, page: null, date_from: null, date_to: null })
  const setPage = (next: number) => update({ page: next > 1 ? String(next) : null })
  const setFilters = (next: PurchaseReportFilterValues) =>
    update({
      supplier_id: next.supplier_id,
      warehouse_id: next.warehouse_id,
      status: next.status,
      date_from: next.dateFrom,
      date_to: next.dateTo,
    })

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader title="Purchase Report" description="Purchase orders, receiving, and pricing across every supplier." />

      <div className="flex items-center gap-1 rounded-md border p-1">
        {TABS.map((option) => (
          <Button key={option.value} size="sm" variant={tab === option.value ? 'default' : 'ghost'} onClick={() => setTab(option.value)}>
            {option.label}
          </Button>
        ))}
      </div>

      {tab === 'orders' && <PurchaseOrdersPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
      {tab === 'by-supplier' && <PurchaseBySupplierPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
      {tab === 'by-item' && <PurchaseByItemPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
      {tab === 'po-tracking' && <PoTrackingPanel filters={filters} onFiltersChange={setFilters} page={page} onPageChange={setPage} />}
    </div>
  )
}
