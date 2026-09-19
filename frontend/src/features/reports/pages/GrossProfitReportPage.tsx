import { useState } from 'react'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { GrossProfitPanel } from '../components/GrossProfitPanel'
import { currentMonthGrossProfitFilters } from '../lib/reportFilters'
import type { SalesReportFilterValues } from '../types'

/**
 * Gross Profit — split out of Sales Report's old Margin tab (2026-09-19) into its own top-level
 * Reports entry, sitting alongside Purchase/Goods Receipt/Sales/Delivery/Inventory Stock/AR Detail/
 * AP Detail/General Ledger/Tax. Local (not URL-synced) filter/page state, same convention as the
 * other standalone single-report pages (TaxReportPage, AccountsPayableDetailReportPage) — the
 * By Item/By Invoice/By Customer toggle lives inside GrossProfitPanel itself.
 */
export function GrossProfitReportPage() {
  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState<SalesReportFilterValues>(currentMonthGrossProfitFilters)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader title="Gross Profit" description="Profit and margin over validated Sales Invoice lines, net of Credit Notes." />

      <GrossProfitPanel
        filters={filters}
        onFiltersChange={(next) => {
          setFilters(next)
          setPage(1)
        }}
        page={page}
        onPageChange={setPage}
      />
    </div>
  )
}
