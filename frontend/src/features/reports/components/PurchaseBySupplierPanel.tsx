import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, Package, RotateCw, Trophy, Wallet } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { TableCell, TableRow } from '@/components/ui/table'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportPurchaseBySupplier, fetchPurchaseBySupplier, type PurchaseBySupplierParams } from '../api/purchaseBySupplierApi'
import { PurchaseReportFiltersBar } from './PurchaseReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { PurchaseBySupplierRow, PurchaseReportFilterValues } from '../types'

interface PurchaseBySupplierPanelProps {
  filters: PurchaseReportFilterValues
  onFiltersChange: (filters: PurchaseReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

/**
 * Purchase Report's By Supplier tab — recap of what was actually received (Goods Receipt, net of
 * Returns), per supplier. Default sort (Nilai Pembelian desc) doubles as "Top Supplier" per the
 * ticket — no separate ranking page.
 */
export function PurchaseBySupplierPanel({ filters, onFiltersChange, page, onPageChange }: PurchaseBySupplierPanelProps) {
  const [sort, setSort] = useState<DataTableSort>({ key: 'amount', direction: 'desc' })
  const [isExporting, setIsExporting] = useState(false)

  const activeParams: Omit<PurchaseBySupplierParams, 'page'> = {
    sort: sort.key as PurchaseBySupplierParams['sort'],
    sort_dir: sort.direction,
    ...(filters.supplier_id ? { supplier_id: filters.supplier_id } : {}),
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['purchase-by-supplier', page, sort.key, sort.direction, filters],
    queryFn: () => fetchPurchaseBySupplier({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const rows = listQuery.data?.data ?? []
  const kpis = listQuery.data?.meta.kpis
  const totalPurchases = kpis?.total_purchases ?? 0

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportPurchaseBySupplier(activeParams, format)
      downloadBlob(reportFileName('PurchaseBySupplierReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<PurchaseBySupplierRow>[] = [
    { header: 'Kode Supplier', accessor: (row) => row.supplier_code },
    { header: 'Nama Supplier', accessor: (row) => row.supplier_name, sortKey: 'supplier_name' },
    { header: 'Jml Penerimaan', accessor: (row) => formatNumber(row.receipt_count), className: 'text-right', sortKey: 'receipt_count' },
    { header: 'Total Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right', sortKey: 'qty' },
    { header: 'Nilai Pembelian', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
    {
      header: '% dari Total',
      accessor: (row) => (totalPurchases > 0 ? `${((row.amount / totalPurchases) * 100).toFixed(2)}%` : '—'),
      className: 'text-right',
    },
  ]

  const hasFilters = !!(filters.supplier_id || filters.warehouse_id)

  const footerRow = kpis && (
    <TableRow>
      <TableCell colSpan={2}>Grand Total</TableCell>
      <TableCell className="text-right">{formatNumber(rows.reduce((sum, r) => sum + r.receipt_count, 0))}</TableCell>
      <TableCell className="text-right">{formatNumber(rows.reduce((sum, r) => sum + r.qty, 0))}</TableCell>
      <TableCell className="text-right">{formatCurrency(kpis.total_purchases)}</TableCell>
      <TableCell className="text-right">100.00%</TableCell>
    </TableRow>
  )

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-3">
        <SummaryCard title="Total Pembelian" value={formatCurrency(kpis?.total_purchases ?? 0)} icon={Wallet} isLoading={listQuery.isLoading} />
        <SummaryCard title="Jumlah Supplier Aktif" value={formatNumber(kpis?.active_supplier_count ?? 0)} icon={Package} isLoading={listQuery.isLoading} />
        <SummaryCard
          title="Supplier Terbesar"
          value={kpis?.top_supplier_name ?? '—'}
          description={kpis ? formatCurrency(kpis.top_supplier_amount) : undefined}
          icon={Trophy}
          isLoading={listQuery.isLoading}
        />
      </div>

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
        footerRow={footerRow}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
