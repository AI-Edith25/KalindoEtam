import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, ExternalLink, Printer, RotateCw, Upload } from 'lucide-react'
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
import { DeliveryReportFiltersBar } from '../components/DeliveryReportFiltersBar'
import { emptyDeliveryReportFilters } from '../lib/reportFilters'
import type { DeliveryReportFilterValues } from '../types'

/** One row per delivery line — the Item filter and per-line Quantity column need line-level data, not the delivery aggregate. */
interface DeliveryReportRow {
  id: string
  deliveryId: string
  document_number: string | null
  sales_order_id: string
  customer_name: string
  warehouse_name: string
  delivery_date: string
  item_code: string | null
  item_name: string | null
  qty: number
}

/** Read-only report over Delivery — reuses fetchDeliveries() as-is, no new endpoint. */
export function DeliveryReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<DeliveryReportFilterValues>(emptyDeliveryReportFilters)

  const listQuery = useQuery({
    queryKey: [
      'delivery-report',
      page,
      search,
      filters.customer_id,
      filters.item_id,
      filters.warehouse_id,
      filters.dateFrom,
      filters.dateTo,
    ],
    queryFn: () =>
      fetchDeliveries({
        page,
        ...(search ? { search } : {}),
        ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
        ...(filters.item_id ? { item_id: filters.item_id } : {}),
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

  const rows = useMemo<DeliveryReportRow[]>(() => {
    const deliveries = listQuery.data?.data ?? []
    return deliveries.flatMap((delivery) =>
      delivery.items.map((item) => ({
        id: item.id,
        deliveryId: delivery.id,
        document_number: delivery.document_number,
        sales_order_id: delivery.sales_order_id,
        customer_name: delivery.customer?.customer_name ?? '—',
        warehouse_name: delivery.warehouse?.name ?? '—',
        delivery_date: delivery.delivery_date,
        item_code: item.item_code,
        item_name: item.item_name,
        qty: item.qty,
      })),
    )
  }, [listQuery.data])

  const columns: DataTableColumn<DeliveryReportRow>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.delivery_date) },
    { header: 'Delivery No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer_name },
    { header: 'Item', accessor: (row) => row.item_name ?? row.item_code ?? '—' },
    { header: 'Quantity', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
    { header: 'Warehouse', accessor: (row) => row.warehouse_name },
    {
      header: 'Reference Document',
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
  ]

  const hasFilters = !!(
    search ||
    filters.customer_id ||
    filters.item_id ||
    filters.warehouse_id ||
    filters.dateFrom ||
    filters.dateTo
  )

  const printParams = new URLSearchParams({
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }).toString()

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Delivery Report"
        description="Goods delivered against sales orders, across every warehouse."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} deliveries` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              {
                label: 'Print',
                icon: Printer,
                onClick: () => navigate(`/reports/deliveries/print${printParams ? `?${printParams}` : ''}`),
              },
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
        onRowClick={(row) => navigate(`/sales/deliveries/${row.deliveryId}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
