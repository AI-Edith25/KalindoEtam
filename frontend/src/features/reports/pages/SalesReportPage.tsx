import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, FileText, Printer, RotateCw, Upload, Wallet } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { fetchSalesOrders } from '@/features/sales/api/salesOrderApi'
import type { SalesOrderStatus } from '@/features/sales/types'
import { SalesReportFiltersBar } from '../components/SalesReportFiltersBar'
import { emptySalesReportFilters } from '../lib/reportFilters'
import type { SalesReportFilterValues } from '../types'

/** One row per sales order line — order-level fields repeated per item, same flattening SalesReportPrintPage mirrors. */
interface SalesReportRow {
  id: string
  orderId: string
  document_number: string | null
  customer_name: string
  sales_person_name: string
  branch_name: string
  order_date: string
  status: SalesOrderStatus
  item_code: string | null
  item_name: string | null
  qty: number
  amount: string | number
}

interface SalesPersonRecapRow {
  name: string
  qty: number
  amount: number
}

const salesPersonRecapColumns: DataTableColumn<SalesPersonRecapRow>[] = [
  { header: 'Sales Person', accessor: (row) => row.name },
  { header: 'Total Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Total Nominal', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

/** Read-only report over Sales Order — reuses fetchSalesOrders() as-is, no new endpoint. */
export function SalesReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<SalesReportFilterValues>(emptySalesReportFilters)

  const listQuery = useQuery({
    queryKey: [
      'sales-report',
      page,
      search,
      filters.customer_id,
      filters.item_id,
      filters.sales_person_id,
      filters.branch_id,
      filters.status,
      filters.dateFrom,
      filters.dateTo,
    ],
    queryFn: () =>
      fetchSalesOrders({
        page,
        ...(search ? { search } : {}),
        ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
        ...(filters.item_id ? { item_id: filters.item_id } : {}),
        ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
        ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  // Flattened to one row per order line — the Item filter and Qty/Sales Amount columns need line-level data, not the order aggregate.
  const rows = useMemo<SalesReportRow[]>(() => {
    const orders = listQuery.data?.data ?? []
    return orders.flatMap((order) =>
      order.items.map((item) => ({
        id: item.id,
        orderId: order.id,
        document_number: order.document_number,
        customer_name: order.customer?.customer_name ?? '—',
        sales_person_name: order.sales_person?.name ?? 'Unassigned',
        branch_name: order.branch?.name ?? '—',
        order_date: order.order_date,
        status: order.status,
        item_code: item.item_code,
        item_name: item.item_name,
        qty: item.qty,
        amount: item.amount,
      })),
    )
  }, [listQuery.data])

  // ponytail: summed from the currently-loaded page only, not a backend aggregate — same ceiling as Purchase Report's Total Amount card.
  const totalRevenue = useMemo(() => rows.reduce((sum, row) => sum + Number(row.amount), 0), [rows])

  // ponytail: same page-local aggregation ceiling as totalRevenue above — grouped over the currently-loaded page only.
  const salesPersonRecap = useMemo<SalesPersonRecapRow[]>(() => {
    const byName = new Map<string, { qty: number; amount: number }>()
    for (const row of rows) {
      const entry = byName.get(row.sales_person_name) ?? { qty: 0, amount: 0 }
      entry.qty += row.qty
      entry.amount += Number(row.amount)
      byName.set(row.sales_person_name, entry)
    }
    return Array.from(byName.entries()).map(([name, totals]) => ({ name, ...totals }))
  }, [rows])

  const columns: DataTableColumn<SalesReportRow>[] = [
    { header: 'Sales No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer_name },
    { header: 'Sales Person', accessor: (row) => row.sales_person_name },
    { header: 'Branch', accessor: (row) => row.branch_name },
    { header: 'Date', accessor: (row) => formatDate(row.order_date) },
    { header: 'Item', accessor: (row) => row.item_name ?? row.item_code ?? '—' },
    { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
    { header: 'Sales Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  ]

  const hasFilters = !!(
    search ||
    filters.customer_id ||
    filters.item_id ||
    filters.sales_person_id ||
    filters.branch_id ||
    filters.status ||
    filters.dateFrom ||
    filters.dateTo
  )

  const printParams = new URLSearchParams({
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
    ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
    ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }).toString()

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Sales Report"
        description="Sales orders across every customer and status."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} orders` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              {
                label: 'Print',
                icon: Printer,
                onClick: () => navigate(`/reports/sales/print${printParams ? `?${printParams}` : ''}`),
              },
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

      {salesPersonRecap.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Sales Achievement by Sales Person</CardTitle>
          </CardHeader>
          <CardContent>
            <DataTable columns={salesPersonRecapColumns} data={salesPersonRecap} rowKey={(row) => row.name} />
          </CardContent>
        </Card>
      )}

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
        onRowClick={(row) => navigate(`/sales/orders/${row.orderId}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
