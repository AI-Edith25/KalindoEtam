import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Printer, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { fetchAccountsReceivables } from '@/features/payment/api/accountsReceivableApi'
import type { AccountsReceivable } from '@/features/payment/types'
import { AccountsReceivableDetailReportFiltersBar } from '../components/AccountsReceivableDetailReportFiltersBar'
import { emptyArDetailReportFilters } from '../lib/reportFilters'
import type { ArDetailReportFilterValues } from '../types'

/** Read-only report over Accounts Receivable — reuses fetchAccountsReceivables() as-is, no new endpoint. */
export function AccountsReceivableDetailReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ArDetailReportFilterValues>(emptyArDetailReportFilters)

  const listQuery = useQuery({
    queryKey: [
      'ar-detail-report',
      page,
      filters.customer_id,
      filters.status,
      filters.agingBucket,
      filters.dateFrom,
      filters.dateTo,
      filters.invoiceDateFrom,
      filters.invoiceDateTo,
    ],
    queryFn: () =>
      fetchAccountsReceivables({
        page,
        ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.agingBucket ? { aging_bucket: filters.agingBucket } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
        ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
        ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const allRows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])
  // No server-side search on this endpoint — narrow the currently-loaded page client-side, same ceiling as every other report's Search box.
  const rows = useMemo(
    () =>
      search
        ? allRows.filter(
            (row) =>
              row.customer?.customer_name.toLowerCase().includes(search.toLowerCase()) ||
              row.invoice?.document_number?.toLowerCase().includes(search.toLowerCase()),
          )
        : allRows,
    [allRows, search],
  )

  const columns: DataTableColumn<AccountsReceivable>[] = [
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Sales Person', accessor: (row) => row.sales_person_name ?? '—' },
    { header: 'Invoice Number', accessor: (row) => row.invoice?.document_number ?? '—' },
    { header: 'Invoice Date', accessor: (row) => (row.invoice?.invoice_date ? formatDate(row.invoice.invoice_date) : '—') },
    { header: 'Masa', accessor: (row) => (row.terms_of_payment_days !== null ? `${row.terms_of_payment_days} hari` : '—') },
    { header: 'Umur', accessor: (row) => (row.age_in_days !== null ? `${row.age_in_days} hari` : '—') },
    { header: 'Due Date', accessor: (row) => formatDate(row.due_date) },
    { header: 'Total Invoice', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
    { header: 'Paid Amount', accessor: (row) => formatCurrency(row.paid_amount), className: 'text-right' },
    { header: 'Outstanding Amount', accessor: (row) => formatCurrency(row.outstanding_amount), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  ]

  const hasFilters = !!(
    search ||
    filters.customer_id ||
    filters.status ||
    filters.agingBucket ||
    filters.dateFrom ||
    filters.dateTo ||
    filters.invoiceDateFrom ||
    filters.invoiceDateTo
  )

  const printParams = new URLSearchParams({
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.agingBucket ? { aging_bucket: filters.agingBucket } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
    ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
  }).toString()

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="AR Detail"
        description="Every outstanding and settled receivable, by customer and invoice."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} receivables` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              {
                label: 'Print',
                icon: Printer,
                onClick: () => navigate(`/reports/ar-detail/print${printParams ? `?${printParams}` : ''}`),
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
          placeholder="Search invoice number or customer…"
        />
        <AccountsReceivableDetailReportFiltersBar
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
        emptyMessage={hasFilters ? 'No receivables match your search or filters.' : 'No receivables yet.'}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      {listQuery.data?.meta && (
        <Card>
          <CardContent className="flex items-center justify-end gap-2 py-4 text-base">
            <span className="text-muted-foreground">Total Outstanding</span>
            <span className="font-semibold">{formatCurrency(listQuery.data.meta.total_outstanding)}</span>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
