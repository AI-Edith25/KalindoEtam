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
import { fetchGoodsReceipts } from '@/features/purchase/api/goodsReceiptApi'
import { fetchPurchaseOrders } from '@/features/purchase/api/purchaseOrderApi'
import type { GoodsReceipt } from '@/features/purchase/types'
import { GoodsReceiptReportFiltersBar } from '../components/GoodsReceiptReportFiltersBar'
import { emptyGoodsReceiptReportFilters } from '../lib/reportFilters'
import type { GoodsReceiptReportFilterValues } from '../types'

/** Read-only report over Goods Receipt — reuses fetchGoodsReceipts() as-is, no new endpoint. */
export function GoodsReceiptReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<GoodsReceiptReportFilterValues>(emptyGoodsReceiptReportFilters)

  const listQuery = useQuery({
    queryKey: ['goods-receipt-report', page, search, filters.warehouse_id, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchGoodsReceipts({
        page,
        ...(search ? { search } : {}),
        ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  // GoodsReceiptResource doesn't nest its purchase_order — same client-side lookup-join as GoodsReceiptListPage.
  const purchaseOrdersLookup = useQuery({
    queryKey: ['purchase-orders-lookup'],
    queryFn: () => fetchPurchaseOrders({ page: 1, per_page: 100 }),
  })
  const purchaseOrderNumber = (purchaseOrderId: string) =>
    purchaseOrdersLookup.data?.data.find((po) => po.id === purchaseOrderId)?.document_number ?? '—'

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  const columns: DataTableColumn<GoodsReceipt>[] = [
    { header: 'Receipt No', accessor: (row) => row.document_number ?? '—' },
    {
      header: 'Purchase No',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/purchase/orders/${row.purchase_order_id}`)
          }}
        >
          {purchaseOrderNumber(row.purchase_order_id)}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.receipt_date) },
    {
      header: 'Received Qty',
      accessor: (row) => formatNumber(row.items.reduce((sum, line) => sum + line.qty, 0)),
      className: 'text-right',
    },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Goods Receipt Report"
        description="Goods received against purchase orders, across every warehouse."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} receipts` : undefined}
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
          placeholder="Search document number or supplier…"
        />
        <GoodsReceiptReportFiltersBar
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
        emptyMessage={hasFilters ? 'No goods receipts match your search or filters.' : 'No goods receipts yet.'}
        onRowClick={(row) => navigate(`/purchase/goods-receipts/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
