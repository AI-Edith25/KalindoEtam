import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, Download, FileText, Info, Printer, Receipt, RotateCw, Wallet } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatDate } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportSalesListing, fetchSalesListing, type SalesListingParams } from '../api/salesListingApi'
import { SalesReportFiltersBar } from './SalesReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { PaymentStatus, SalesListingRow, SalesListingType, SalesReportFilterValues } from '../types'

interface SalesListingPanelProps {
  filters: SalesReportFilterValues
  onFiltersChange: (filters: SalesReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

const ALL = '__all__'

export function SalesListingPanel({ filters, onFiltersChange, page, onPageChange }: SalesListingPanelProps) {
  const navigate = useNavigate()
  const [sort, setSort] = useState<DataTableSort>({ key: 'date', direction: 'desc' })
  const [type, setType] = useState<SalesListingType | null>(null)
  const [paymentStatus, setPaymentStatus] = useState<PaymentStatus | null>(null)
  const [isExporting, setIsExporting] = useState(false)

  const activeParams: Omit<SalesListingParams, 'page'> = {
    sort: sort.key as SalesListingParams['sort'],
    sort_dir: sort.direction,
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
    ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(type ? { type } : {}),
    ...(paymentStatus ? { payment_status: paymentStatus } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['sales-listing', page, sort.key, sort.direction, filters, type, paymentStatus],
    queryFn: () => fetchSalesListing({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const rows = listQuery.data?.data ?? []
  const kpis = listQuery.data?.meta.kpis

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportSalesListing(activeParams, format)
      downloadBlob(reportFileName('SalesListingReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<SalesListingRow>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.date), sortKey: 'date' },
    { header: 'Document', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Reference SO', accessor: (row) => row.reference_so_number ?? '—' },
    { header: 'Reference DO', accessor: (row) => row.reference_do_number ?? '—' },
    { header: 'Customer Code', accessor: (row) => row.customer_code },
    { header: 'Customer Name', accessor: (row) => row.customer_name, sortKey: 'customer_name' },
    { header: 'Type', accessor: (row) => <StatusBadge status={row.type} /> },
    { header: 'Amount Excl. Tax', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
    { header: 'Disc Adjustment', accessor: (row) => formatCurrency(row.discount), className: 'text-right' },
    { header: 'Tax', accessor: (row) => formatCurrency(row.tax), className: 'text-right' },
    { header: 'Amount Incl. Tax', accessor: (row) => formatCurrency(row.amount_incl_tax), className: 'text-right', sortKey: 'amount_incl_tax' },
    { header: 'Payment Status', accessor: (row) => (row.payment_status ? <StatusBadge status={row.payment_status} /> : '—') },
    { header: 'Outstanding AR', accessor: (row) => (row.outstanding_ar !== null ? formatCurrency(row.outstanding_ar) : '—'), className: 'text-right' },
  ]

  const hasFilters = !!(filters.customer_id || filters.sales_person_id || filters.branch_id || type || paymentStatus)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start gap-2 rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
        <Info className="mt-0.5 size-4 shrink-0" />
        <p>&quot;Revenue&quot; here means invoiced (billed), not cash received — see the Payment Status/Outstanding AR columns for what&apos;s actually been collected.</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <SummaryCard title="Net Sales (Excl. Tax)" value={formatCurrency(kpis?.net_sales ?? 0)} icon={Wallet} isLoading={listQuery.isLoading} />
        <SummaryCard title="Total Tax" value={formatCurrency(kpis?.total_tax ?? 0)} icon={Receipt} isLoading={listQuery.isLoading} />
        <SummaryCard title="Gross (Incl. Tax)" value={formatCurrency(kpis?.gross ?? 0)} icon={FileText} isLoading={listQuery.isLoading} />
        <SummaryCard title="Paid" value={formatCurrency(kpis?.paid_value ?? 0)} icon={CheckCircle2} isLoading={listQuery.isLoading} />
        <SummaryCard title="Unpaid" value={formatCurrency(kpis?.unpaid_value ?? 0)} icon={Wallet} tone={kpis && kpis.unpaid_value > 0 ? 'warning' : 'default'} isLoading={listQuery.isLoading} />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1 rounded-md border p-1">
            <Button size="sm" variant={type === null ? 'default' : 'ghost'} onClick={() => { setType(null); onPageChange(1) }}>All</Button>
            <Button size="sm" variant={type === 'invoice' ? 'default' : 'ghost'} onClick={() => { setType('invoice'); onPageChange(1) }}>Sales Invoice</Button>
            <Button size="sm" variant={type === 'credit_note' ? 'default' : 'ghost'} onClick={() => { setType('credit_note'); onPageChange(1) }}>Credit Note</Button>
          </div>
          <Select
            value={paymentStatus ?? ALL}
            onValueChange={(next) => { setPaymentStatus(next === ALL ? null : (next as PaymentStatus)); onPageChange(1) }}
          >
            <SelectTrigger className="w-44">
              <SelectValue placeholder="All payment statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All payment statuses</SelectItem>
              <SelectItem value="unpaid">Unpaid</SelectItem>
              <SelectItem value="partially_paid">Partial</SelectItem>
              <SelectItem value="paid">Paid</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            {
              label: 'Print',
              icon: Printer,
              onClick: () => {
                const params = new URLSearchParams({
                  tab: 'listing',
                  ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
                  ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
                  ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
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
        hide={['item', 'itemGroup', 'status']}
      />

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'Tidak ada data untuk filter ini.' : 'No sales in this period yet.'}
        sort={sort}
        onSortChange={(key) => setSort((prev) => (prev.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'desc' }))}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
