import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Download, ListTree, RotateCw } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn, formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportPoTracking, fetchPoTracking, fetchPoTrackingItems, type PoTrackingParams } from '../api/poTrackingApi'
import { PurchaseReportFiltersBar } from './PurchaseReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { PoTrackingRow, PurchaseReportFilterValues, ReceivingStatus } from '../types'

interface PoTrackingPanelProps {
  filters: PurchaseReportFilterValues
  onFiltersChange: (filters: PurchaseReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

const ALL = '__all__'
const STATUS_LABELS: Record<ReceivingStatus, string> = { not_received: 'Belum Diterima', partial: 'Sebagian', complete: 'Lengkap' }
const STATUS_STYLES: Record<ReceivingStatus, string> = {
  not_received: 'bg-muted text-muted-foreground border-transparent',
  partial: 'bg-amber-100 text-amber-700 border-transparent dark:bg-amber-950 dark:text-amber-300',
  complete: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
}

/** Purchase Report's PO Tracking tab — submitted Purchase Orders whose Goods Receipts haven't fully arrived yet. "Hanya yang belum lengkap" defaults ON — the tab's main purpose. */
export function PoTrackingPanel({ filters, onFiltersChange, page, onPageChange }: PoTrackingPanelProps) {
  const [sort, setSort] = useState<DataTableSort>({ key: 'order_date', direction: 'asc' })
  const [receivingStatus, setReceivingStatus] = useState<ReceivingStatus | null>(null)
  const [incompleteOnly, setIncompleteOnly] = useState(true)
  const [isExporting, setIsExporting] = useState(false)
  const [itemsFor, setItemsFor] = useState<PoTrackingRow | null>(null)

  const activeParams: Omit<PoTrackingParams, 'page'> = {
    sort: sort.key as PoTrackingParams['sort'],
    sort_dir: sort.direction,
    ...(filters.supplier_id ? { supplier_id: filters.supplier_id } : {}),
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(receivingStatus ? { receiving_status: receivingStatus } : {}),
    incomplete_only: incompleteOnly,
  }

  const listQuery = useQuery({
    queryKey: ['po-tracking', page, sort.key, sort.direction, filters, receivingStatus, incompleteOnly],
    queryFn: () => fetchPoTracking({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const itemsQuery = useQuery({
    queryKey: ['po-tracking-items', itemsFor?.id],
    queryFn: () => fetchPoTrackingItems(itemsFor!.id),
    enabled: !!itemsFor,
  })

  const rows = listQuery.data?.data ?? []

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportPoTracking(activeParams, format)
      downloadBlob(reportFileName('PoTrackingReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<PoTrackingRow>[] = [
    { header: 'Tanggal PO', accessor: (row) => formatDate(row.order_date), sortKey: 'order_date' },
    {
      header: 'No PO',
      accessor: (row) => (
        <span className={cn('inline-flex items-center gap-1.5', row.is_overdue && 'text-destructive')}>
          {row.is_overdue && <AlertTriangle className="size-3.5" />}
          {row.document_number ?? '—'}
        </span>
      ),
      sortKey: 'document_number',
    },
    { header: 'Supplier', accessor: (row) => row.supplier_name },
    { header: 'Nilai PO', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right', sortKey: 'total_amount' },
    { header: 'Qty Dipesan', accessor: (row) => formatNumber(row.ordered_qty), className: 'text-right', sortKey: 'ordered_qty' },
    { header: 'Qty Diterima', accessor: (row) => formatNumber(row.received_qty), className: 'text-right', sortKey: 'received_qty' },
    { header: 'Sisa', accessor: (row) => formatNumber(row.remaining_qty), className: 'text-right', sortKey: 'remaining_qty' },
    { header: '% Terpenuhi', accessor: (row) => `${row.fulfillment_pct.toFixed(2)}%`, className: 'text-right', sortKey: 'fulfillment_pct' },
    { header: 'Status Penerimaan', accessor: (row) => <Badge className={STATUS_STYLES[row.receiving_status]}>{STATUS_LABELS[row.receiving_status]}</Badge> },
    {
      header: '',
      id: 'actions',
      accessor: (row) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={(event) => {
            event.stopPropagation()
            setItemsFor(row)
          }}
        >
          <ListTree className="size-3.5" />
          Items
        </Button>
      ),
    },
  ]

  const hasFilters = !!(filters.supplier_id || filters.warehouse_id || receivingStatus)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <Select
            value={receivingStatus ?? ALL}
            onValueChange={(next) => { setReceivingStatus(next === ALL ? null : (next as ReceivingStatus)); onPageChange(1) }}
          >
            <SelectTrigger className="w-48">
              <SelectValue placeholder="All receiving statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All receiving statuses</SelectItem>
              <SelectItem value="not_received">Belum Diterima</SelectItem>
              <SelectItem value="partial">Sebagian</SelectItem>
              <SelectItem value="complete">Lengkap</SelectItem>
            </SelectContent>
          </Select>
          <label className="flex items-center gap-2 text-sm">
            <Switch checked={incompleteOnly} onCheckedChange={(checked) => { setIncompleteOnly(checked); onPageChange(1) }} />
            Hanya yang belum lengkap
          </label>
        </div>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting },
            { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting },
          ]}
        />
      </div>

      <PurchaseReportFiltersBar value={filters} onChange={(next) => { onFiltersChange(next); onPageChange(1) }} hide={['status']} />

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'Tidak ada PO untuk filter ini.' : 'Semua PO sudah diterima lengkap.'}
        sort={sort}
        onSortChange={(key) => setSort((prev) => (prev.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }))}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}

      <Dialog open={!!itemsFor} onOpenChange={(open) => !open && setItemsFor(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Rincian Item — {itemsFor?.document_number}</DialogTitle>
          </DialogHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead className="text-right">Qty Dipesan</TableHead>
                <TableHead className="text-right">Qty Diterima</TableHead>
                <TableHead className="text-right">Sisa</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {itemsQuery.isLoading ? (
                <TableRow>
                  <TableCell colSpan={4} className="text-center text-muted-foreground">Loading…</TableCell>
                </TableRow>
              ) : itemsQuery.data?.length ? (
                itemsQuery.data.map((row, index) => (
                  <TableRow key={index}>
                    <TableCell>{row.item_name}</TableCell>
                    <TableCell className="text-right">{formatNumber(row.ordered_qty)}</TableCell>
                    <TableCell className="text-right">{formatNumber(row.received_qty)}</TableCell>
                    <TableCell className="text-right">{formatNumber(row.remaining_qty)}</TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={4} className="text-center text-muted-foreground">No items found.</TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </DialogContent>
      </Dialog>
    </div>
  )
}
