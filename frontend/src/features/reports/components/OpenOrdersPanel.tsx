import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Clock, Download, Hash, Printer, RotateCw, Wallet } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportOpenOrders, fetchOpenOrders, type OpenOrdersParams } from '../api/openOrdersApi'
import { SalesReportFiltersBar } from './SalesReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { AgingBucket, OpenOrdersRow, SalesReportFilterValues } from '../types'

interface OpenOrdersPanelProps {
  filters: SalesReportFilterValues
  onFiltersChange: (filters: SalesReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

const AGING_CHIPS: { value: AgingBucket; label: string }[] = [
  { value: '0-7', label: '0–7 days' },
  { value: '8-30', label: '8–30 days' },
  { value: '31-60', label: '31–60 days' },
  { value: 'over_60', label: '>60 days' },
]

const OPEN_ORDER_STATUS_OPTIONS = [
  { value: 'submitted', label: 'Submitted' },
  { value: 'approved', label: 'Approved' },
]

export function OpenOrdersPanel({ filters, onFiltersChange, page, onPageChange }: OpenOrdersPanelProps) {
  const navigate = useNavigate()
  const [sort, setSort] = useState<DataTableSort>({ key: 'outstanding_value', direction: 'desc' })
  const [aging, setAging] = useState<AgingBucket | null>(null)
  const [overdueOnly, setOverdueOnly] = useState(false)
  const [isExporting, setIsExporting] = useState(false)

  const activeParams: Omit<OpenOrdersParams, 'page'> = {
    sort: sort.key as OpenOrdersParams['sort'],
    sort_dir: sort.direction,
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
    ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
    ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
    ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(aging ? { aging } : {}),
    ...(overdueOnly ? { overdue_only: true } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['open-orders', page, sort.key, sort.direction, filters, aging, overdueOnly],
    queryFn: () => fetchOpenOrders({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const rows = listQuery.data?.data ?? []
  const kpis = listQuery.data?.meta.kpis

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportOpenOrders(activeParams, format)
      downloadBlob(reportFileName('OpenOrdersReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<OpenOrdersRow>[] = [
    { header: 'SO Date', accessor: (row) => formatDate(row.order_date), sortKey: 'order_date' },
    { header: 'Sales No', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Customer', accessor: (row) => row.customer_name, sortKey: 'customer_name' },
    { header: 'Sales Person', accessor: (row) => row.sales_person_name },
    { header: 'Branch', accessor: (row) => row.branch_name ?? '—' },
    { header: 'Item', accessor: (row) => row.item_name, sortKey: 'item_name' },
    { header: 'Qty Ordered', accessor: (row) => formatNumber(row.qty_ordered), className: 'text-right' },
    { header: 'Qty Delivered', accessor: (row) => formatNumber(row.qty_delivered), className: 'text-right' },
    { header: 'Qty Invoiced', accessor: (row) => formatNumber(row.qty_invoiced), className: 'text-right' },
    {
      id: 'qty_outstanding',
      header: (
        <TooltipProvider>
          <Tooltip>
            <TooltipTrigger className="underline decoration-dotted underline-offset-2">Qty Outstanding</TooltipTrigger>
            <TooltipContent>Qty Ordered − Qty Invoiced — the amount of this line not yet billed, regardless of delivery status.</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      ),
      accessor: (row) => formatNumber(row.qty_outstanding),
      className: 'text-right',
      sortKey: 'qty_outstanding',
    },
    { header: 'Outstanding Value', accessor: (row) => formatCurrency(row.outstanding_value), className: 'text-right', sortKey: 'outstanding_value' },
    { header: 'Delivery', accessor: (row) => <StatusBadge status={row.delivery_status} /> },
    { header: 'Invoice', accessor: (row) => <StatusBadge status={row.invoice_status} /> },
    { header: 'Age', accessor: (row) => `${row.age_in_days}d` },
    {
      header: 'Overdue',
      accessor: (row) => (row.is_overdue ? <span className="inline-flex items-center gap-1 text-destructive"><AlertTriangle className="size-3.5" /> Yes</span> : '—'),
    },
  ]

  const hasFilters = !!(
    filters.customer_id ||
    filters.item_id ||
    filters.item_group_id ||
    filters.sales_person_id ||
    filters.branch_id ||
    filters.status ||
    aging ||
    overdueOnly
  )

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard title="Total Open Order Value" value={formatCurrency(kpis?.total_outstanding_value ?? 0)} icon={Wallet} isLoading={listQuery.isLoading} />
        <SummaryCard title="Open Sales Orders" value={formatNumber(kpis?.open_so_count ?? 0)} icon={Hash} isLoading={listQuery.isLoading} />
        <SummaryCard
          title="Overdue Value"
          value={formatCurrency(kpis?.overdue_value ?? 0)}
          icon={AlertTriangle}
          tone={kpis && kpis.overdue_value > 0 ? 'danger' : 'default'}
          isLoading={listQuery.isLoading}
        />
        <SummaryCard title="Avg Order Age" value={`${kpis?.avg_age_days ?? 0} days`} icon={Clock} isLoading={listQuery.isLoading} />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          {AGING_CHIPS.map((chip) => (
            <Button
              key={chip.value}
              size="sm"
              variant={aging === chip.value ? 'default' : 'outline'}
              onClick={() => {
                setAging(aging === chip.value ? null : chip.value)
                onPageChange(1)
              }}
            >
              {chip.label}
            </Button>
          ))}
          <Button
            size="sm"
            variant={overdueOnly ? 'default' : 'outline'}
            onClick={() => {
              setOverdueOnly((prev) => !prev)
              onPageChange(1)
            }}
          >
            <AlertTriangle className="size-3.5" />
            Overdue only
          </Button>
        </div>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            {
              label: 'Print',
              icon: Printer,
              onClick: () => {
                const params = new URLSearchParams({
                  tab: 'open-orders',
                  ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
                  ...(filters.item_id ? { item_id: filters.item_id } : {}),
                  ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
                  ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
                  ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
                  ...(filters.status ? { status: filters.status } : {}),
                  ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
                  ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
                })
                navigate(`/reports/sales/print?${params.toString()}`)
              },
            },
            { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting },
            { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting },
          ]}
        />
      </div>

      <SalesReportFiltersBar
        value={filters}
        onChange={(next) => { onFiltersChange(next); onPageChange(1) }}
        statusOptions={OPEN_ORDER_STATUS_OPTIONS}
      />

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'Tidak ada data untuk filter ini.' : 'No open orders right now.'}
        sort={sort}
        onSortChange={(key) => setSort((prev) => (prev.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'desc' }))}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
