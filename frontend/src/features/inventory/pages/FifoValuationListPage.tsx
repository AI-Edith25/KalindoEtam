import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Coins, Download, Layers, Package, RotateCw } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { toastApiError } from '@/shared/services/errorHandler'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { exportFifoValuation, fetchFifoValuation } from '../api/fifoValuationApi'
import { FifoValuationFiltersBar } from '../components/FifoValuationFiltersBar'
import { emptyFifoValuationFilters } from '../lib/fifoValuationFilters'
import type { FifoLayerDetail, FifoValuationFilterValues, FifoValuationGroup } from '../types'

const layerColumns: DataTableColumn<FifoLayerDetail>[] = [
  { header: 'Received Date', accessor: (row) => formatDate(row.received_date) },
  { header: 'Source Document', accessor: (row) => row.source_document_number ?? '—' },
  { header: 'Qty In', accessor: (row) => formatNumber(row.qty_in), className: 'text-right text-muted-foreground' },
  { header: 'Qty Remaining', accessor: (row) => formatNumber(row.qty_remaining), className: 'text-right' },
  { header: 'Unit Cost', accessor: (row) => formatCurrency(row.unit_cost), className: 'text-right' },
  { header: 'Remaining Value', accessor: (row) => formatCurrency(row.remaining_value), className: 'text-right font-medium' },
]

/** Read-only valuation report over FifoLayer — "what do we have, at what cost, right now." Row click drills into that item+warehouse's individual layers. */
export function FifoValuationListPage() {
  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState<FifoValuationFilterValues>(emptyFifoValuationFilters)
  const [drillDown, setDrillDown] = useState<FifoValuationGroup | null>(null)
  const [isExporting, setIsExporting] = useState(false)

  const filterParams = {
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(filters.hideExhausted ? { hide_exhausted: 'true' as const } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['fifo-layers', page, filters],
    queryFn: () => fetchFifoValuation({ page, ...filterParams }),
    placeholderData: (previous) => previous,
  })

  const groups = listQuery.data?.data ?? []
  const summary = listQuery.data?.meta.summary

  const handleExport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportFifoValuation(filterParams, format)
      downloadBlob(`FifoLayers.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<FifoValuationGroup>[] = [
    { header: 'Item', accessor: (row) => `${row.item_code} — ${row.item_name}` },
    { header: 'Warehouse', accessor: (row) => row.warehouse_name },
    { header: 'Qty Remaining', accessor: (row) => formatNumber(row.qty_remaining), className: 'text-right' },
    { header: 'Weighted Avg Cost', accessor: (row) => formatCurrency(row.weighted_average_cost), className: 'text-right text-muted-foreground' },
    { header: 'Total Value', accessor: (row) => formatCurrency(row.total_value), className: 'text-right font-medium' },
  ]

  const hasFilters = filters.warehouse_id !== '' || filters.item_group_id !== '' || filters.item_id !== '' || filters.dateFrom !== '' || filters.dateTo !== ''

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="inventory" />

      <PageHeader
        title="FIFO Layers"
        description="What's on hand, at what cost — grouped by item and warehouse."
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export XLSX', icon: Download, disabled: isExporting, onClick: () => handleExport('xlsx') },
              { label: 'Export CSV', icon: Download, disabled: isExporting, onClick: () => handleExport('csv') },
            ]}
          />
        }
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <SummaryCard
          title="Total Inventory Value"
          value={formatCurrency(summary?.total_value ?? 0)}
          icon={Coins}
          isLoading={listQuery.isLoading}
        />
        <SummaryCard title="Items" value={formatNumber(summary?.item_count ?? 0)} icon={Package} isLoading={listQuery.isLoading} />
        <SummaryCard title="Total Qty" value={formatNumber(summary?.total_qty ?? 0)} icon={Layers} isLoading={listQuery.isLoading} />
      </div>

      <FifoValuationFiltersBar
        value={filters}
        onChange={(value) => {
          setFilters(value)
          setPage(1)
        }}
      />

      <DataTable
        columns={columns}
        data={groups}
        rowKey={(row) => `${row.item_id}-${row.warehouse_id}`}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No layers match your filters.' : 'No FIFO layers yet.'}
        onRowClick={(row) => setDrillDown(row)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <Dialog open={!!drillDown} onOpenChange={(open) => !open && setDrillDown(null)}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>
              {drillDown?.item_code} — {drillDown?.warehouse_name}
            </DialogTitle>
            <DialogDescription>Every layer currently contributing to this item's balance in this warehouse.</DialogDescription>
          </DialogHeader>
          {drillDown && (
            <DataTable columns={layerColumns} data={drillDown.layers} rowKey={(row) => row.id} emptyMessage="No layers." />
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
