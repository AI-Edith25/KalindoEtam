import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, FileText, RotateCw, Upload, Wallet } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { fetchSalesOrders } from '@/features/sales/api/salesOrderApi'
import type { SalesOrder } from '@/features/sales/types'
import { SalesReportFiltersBar } from '../components/SalesReportFiltersBar'
import { emptySalesReportFilters } from '../lib/reportFilters'
import { reportsSectionNav } from '../navigation'
import type { SalesReportFilterValues } from '../types'

/** Read-only report over Sales Order — reuses fetchSalesOrders() as-is, no new endpoint. */
export function SalesReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<SalesReportFilterValues>(emptySalesReportFilters)

  const listQuery = useQuery({
    queryKey: ['sales-report', page, search, filters.customer_id, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchSalesOrders({
        page,
        ...(search ? { search } : {}),
        ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  // ponytail: summed from the currently-loaded page only, not a backend aggregate — same ceiling as Purchase Report's Total Amount card.
  const totalRevenue = useMemo(() => rows.reduce((sum, row) => sum + Number(row.total_amount), 0), [rows])

  const columns: DataTableColumn<SalesOrder>[] = [
    { header: 'Sales No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.order_date) },
    { header: 'Total', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  ]

  const hasFilters = !!(search || filters.customer_id || filters.status || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={reportsSectionNav} />

      <PageHeader
        title="Sales Report"
        description="Sales orders across every customer and status."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} orders` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
          />
        }
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <SummaryCard
          title="Total Sales"
          value={formatNumber(listQuery.data?.meta.total ?? 0)}
          icon={FileText}
          isLoading={listQuery.isLoading}
        />
        <SummaryCard
          title="Total Revenue"
          value={formatCurrency(totalRevenue)}
          description="Sum of the currently loaded page"
          icon={Wallet}
          isLoading={listQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search document number or customer…"
        />
        <SalesReportFiltersBar
          value={filters}
          onChange={(value) => {
            setFilters(value)
            setPage(1)
          }}
        />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No sales orders match your search or filters.' : 'No sales orders yet.'}
        onRowClick={(row) => navigate(`/sales/orders/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
