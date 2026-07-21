import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, ExternalLink, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { formatDate, formatNumber } from '@/lib/utils'
import { fetchDeliveries } from '@/features/sales/api/deliveryApi'
import { fetchSalesOrders } from '@/features/sales/api/salesOrderApi'
import type { Delivery } from '@/features/sales/types'
import { DeliveryReportFiltersBar } from '../components/DeliveryReportFiltersBar'
import { emptyDeliveryReportFilters } from '../lib/reportFilters'
import { reportsSectionNav } from '../navigation'
import type { DeliveryReportFilterValues } from '../types'

/** Read-only report over Delivery — reuses fetchDeliveries() as-is, no new endpoint. */
export function DeliveryReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<DeliveryReportFilterValues>(emptyDeliveryReportFilters)

  const listQuery = useQuery({
    queryKey: ['delivery-report', page, search, filters.warehouse_id, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchDeliveries({
        page,
        ...(search ? { search } : {}),
        ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  // DeliveryResource doesn't nest its sales_order — same client-side lookup-join as DeliveryListPage.
  const salesOrdersLookup = useQuery({
    queryKey: ['sales-orders-lookup'],
    queryFn: () => fetchSalesOrders({ page: 1, per_page: 100 }),
  })
  const salesOrderNumber = (salesOrderId: string) =>
    salesOrdersLookup.data?.data.find((so) => so.id === salesOrderId)?.document_number ?? '—'

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  const columns: DataTableColumn<Delivery>[] = [
    { header: 'Delivery No', accessor: (row) => row.document_number ?? '—' },
    {
      header: 'Sales Order',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/sales/orders/${row.sales_order_id}`)
          }}
        >
          {salesOrderNumber(row.sales_order_id)}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.delivery_date) },
    {
      header: 'Delivered Qty',
      accessor: (row) => formatNumber(row.items.reduce((sum, line) => sum + line.qty, 0)),
      className: 'text-right',
    },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={reportsSectionNav} />

      <PageHeader
        title="Delivery Report"
        description="Goods delivered against sales orders, across every warehouse."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} deliveries` : undefined}
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

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search delivery number or customer…"
        />
        <DeliveryReportFiltersBar
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
        emptyMessage={hasFilters ? 'No deliveries match your search or filters.' : 'No deliveries yet.'}
        onRowClick={(row) => navigate(`/sales/deliveries/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
