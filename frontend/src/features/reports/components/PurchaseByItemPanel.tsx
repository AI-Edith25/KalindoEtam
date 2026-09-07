import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, History, RotateCw, TrendingUp } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { cn, formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportPurchaseByItem, fetchPurchaseByItem, fetchPurchaseByItemHistory, type PurchaseByItemParams } from '../api/purchaseByItemApi'
import { PurchaseReportFiltersBar } from './PurchaseReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { PurchaseByItemRow, PurchaseReportFilterValues } from '../types'

interface PurchaseByItemPanelProps {
  filters: PurchaseReportFilterValues
  onFiltersChange: (filters: PurchaseReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

const price = (value: number | null) => (value !== null ? formatCurrency(value) : '—')

/**
 * Purchase Report's By Item tab — recap of what was actually received (Goods Receipt, net of
 * Returns), per item, plus price monitoring. Replaces the legacy "Product Purchase History Price"
 * report via the row-click transaction history dialog below.
 */
export function PurchaseByItemPanel({ filters, onFiltersChange, page, onPageChange }: PurchaseByItemPanelProps) {
  const [sort, setSort] = useState<DataTableSort>({ key: 'amount', direction: 'desc' })
  const [isExporting, setIsExporting] = useState(false)
  const [historyFor, setHistoryFor] = useState<PurchaseByItemRow | null>(null)

  const activeParams: Omit<PurchaseByItemParams, 'page'> = {
    sort: sort.key as PurchaseByItemParams['sort'],
    sort_dir: sort.direction,
    ...(filters.supplier_id ? { supplier_id: filters.supplier_id } : {}),
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['purchase-by-item', page, sort.key, sort.direction, filters],
    queryFn: () => fetchPurchaseByItem({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const historyQuery = useQuery({
    queryKey: ['purchase-by-item-history', historyFor?.id, filters],
    queryFn: () => fetchPurchaseByItemHistory(historyFor!.id, activeParams),
    enabled: !!historyFor,
  })

  const rows = listQuery.data?.data ?? []

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportPurchaseByItem(activeParams, format)
      downloadBlob(reportFileName('PurchaseByItemReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<PurchaseByItemRow>[] = [
    { header: 'Kode Item', accessor: (row) => row.item_code ?? '—' },
    { header: 'Nama Item', accessor: (row) => row.item_name, sortKey: 'item_name' },
    { header: 'UOM', accessor: (row) => row.uom ?? '—' },
    { header: 'Qty Dibeli', accessor: (row) => formatNumber(row.qty), className: 'text-right', sortKey: 'qty' },
    { header: 'Nilai Pembelian', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
    { header: 'Harga Rata-rata', accessor: (row) => price(row.avg_price), className: 'text-right', sortKey: 'avg_price' },
    {
      header: 'Harga Terakhir',
      accessor: (row) => {
        const rising = row.last_price !== null && row.avg_price !== null && row.last_price > row.avg_price
        return (
          <span className={cn('inline-flex items-center gap-1', rising && 'text-destructive')}>
            {price(row.last_price)}
            {rising && (
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger>
                    <TrendingUp className="size-3.5" />
                  </TooltipTrigger>
                  <TooltipContent>Harga terakhir lebih tinggi dari rata-rata</TooltipContent>
                </Tooltip>
              </TooltipProvider>
            )}
          </span>
        )
      },
      className: 'text-right',
      sortKey: 'last_price',
    },
    { header: 'Harga Terendah', accessor: (row) => price(row.lowest_price), className: 'text-right', sortKey: 'lowest_price' },
    { header: 'Harga Tertinggi', accessor: (row) => price(row.highest_price), className: 'text-right', sortKey: 'highest_price' },
    {
      header: '',
      id: 'actions',
      accessor: (row) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={(event) => {
            event.stopPropagation()
            setHistoryFor(row)
          }}
        >
          <History className="size-3.5" />
          History
        </Button>
      ),
    },
  ]

  const hasFilters = !!(filters.supplier_id || filters.warehouse_id)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-end gap-3">
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
        emptyMessage={hasFilters ? 'Tidak ada data untuk filter ini.' : 'No purchases in this period yet.'}
        sort={sort}
        onSortChange={(key) => setSort((prev) => (prev.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'desc' }))}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}

      <Dialog open={!!historyFor} onOpenChange={(open) => !open && setHistoryFor(null)}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Riwayat Pembelian — {historyFor?.item_name}</DialogTitle>
          </DialogHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Tanggal</TableHead>
                <TableHead>No GR</TableHead>
                <TableHead>No PO</TableHead>
                <TableHead>Supplier</TableHead>
                <TableHead className="text-right">Qty</TableHead>
                <TableHead className="text-right">Harga Satuan</TableHead>
                <TableHead className="text-right">Nilai</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {historyQuery.isLoading ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground">Loading…</TableCell>
                </TableRow>
              ) : historyQuery.data?.length ? (
                historyQuery.data.map((row, index) => (
                  <TableRow key={index}>
                    <TableCell>{formatDate(row.date)}</TableCell>
                    <TableCell>{row.gr_number ?? '—'}</TableCell>
                    <TableCell>{row.po_number ?? 'Direct Receipt'}</TableCell>
                    <TableCell>{row.supplier_name}</TableCell>
                    <TableCell className="text-right">{formatNumber(row.qty)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(row.rate)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(row.amount)}</TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground">No transactions found.</TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </DialogContent>
      </Dialog>
    </div>
  )
}
