import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, FileText, RotateCw, Upload, Wallet } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { fetchPurchaseOrders } from '@/features/purchase/api/purchaseOrderApi'
import type { PurchaseOrder } from '@/features/purchase/types'
import { PurchaseReportFiltersBar } from './PurchaseReportFiltersBar'
import type { PurchaseReportFilterValues } from '../types'

interface PurchaseOrdersPanelProps {
  filters: PurchaseReportFilterValues
  onFiltersChange: (filters: PurchaseReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

/** Purchase Report's Purchase Orders tab — today's original page content, unchanged in structure/columns. Reuses fetchPurchaseOrders() as-is, no dedicated backend report endpoint. */
export function PurchaseOrdersPanel({ filters, onFiltersChange, page, onPageChange }: PurchaseOrdersPanelProps) {
  const navigate = useNavigate()

  const [search, setSearch] = useState('')

  const listQuery = useQuery({
    queryKey: ['purchase-report', page, search, filters.supplier_id, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchPurchaseOrders({
        page,
        ...(search ? { search } : {}),
        ...(filters.supplier_id ? { supplier_id: filters.supplier_id } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  // ponytail: summed from the currently-loaded page only, not a backend aggregate — same ceiling
  // as the page-1-only lookup helper. Fine while every report's dataset fits on one page. Draft
  // POs are excluded so a big pending order (e.g. a Draft worth hundreds of millions) never reads
  // as already-committed spend — see "Nilai PO (tanpa Draft)" below.
  const nilaiPoTanpaDraft = useMemo(
    () => rows.filter((row) => row.status !== 'draft').reduce((sum, row) => sum + Number(row.total_amount), 0),
    [rows]
  )

  const columns: DataTableColumn<PurchaseOrder>[] = [
    { header: 'Purchase No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Supplier', accessor: (row) => row.supplier?.supplier_name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.order_date) },
    { header: 'Total', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  ]

  const hasFilters = !!(search || filters.supplier_id || filters.status || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div />
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Export', icon: Download, disabled: true },
            { label: 'Import', icon: Upload, disabled: true },
          ]}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <SummaryCard
          title="Jumlah PO"
          value={formatNumber(listQuery.data?.meta.total ?? 0)}
          icon={FileText}
          isLoading={listQuery.isLoading}
        />
        <SummaryCard
          title="Nilai PO (tanpa Draft)"
          value={formatCurrency(nilaiPoTanpaDraft)}
          description="Sum of the currently loaded page, excluding Draft"
          icon={Wallet}
          isLoading={listQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox
          value={search}
          onChange={(value) => {
            setSearch(value)
            onPageChange(1)
          }}
          placeholder="Search document number or supplier…"
        />
        <PurchaseReportFiltersBar
          value={filters}
          onChange={(value) => {
            onFiltersChange(value)
            onPageChange(1)
          }}
          hide={['warehouse']}
        />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No purchase orders match your search or filters.' : 'No purchase orders yet.'}
        onRowClick={(row) => navigate(`/purchase/orders/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
