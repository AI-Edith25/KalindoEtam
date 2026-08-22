import { Fragment, useEffect, useMemo, useState } from 'react'
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
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { exportAccountsReceivables, fetchAccountsReceivables } from '@/features/payment/api/accountsReceivableApi'
import { fetchAccountsReceivablesAll } from '../api/accountsReceivableCustomerReportsApi'
import type { AccountsReceivable } from '@/features/payment/types'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { AccountsReceivableDetailReportFiltersBar } from '../components/AccountsReceivableDetailReportFiltersBar'
import { fetchAccountsReceivableGroupedDetail } from '../api/accountsReceivableGroupedDetailApi'
import { emptyArDetailReportFilters } from '../lib/reportFilters'
import type { ArDetailReportFilterValues } from '../types'

type ViewMode = 'aging' | 'grouped'

/** Read-only report over Accounts Receivable — reuses fetchAccountsReceivables() as-is, no new endpoint. */
export function AccountsReceivableDetailReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ArDetailReportFilterValues>(emptyArDetailReportFilters)
  const [viewMode, setViewMode] = useState<ViewMode>('aging')
  // invoice_ids — same selection mechanism as Sales > Invoices' checkbox print flow, reused here
  // so "export only selected" needs no new backend filter, just this set threaded into the export call.
  const [selectedInvoiceIds, setSelectedInvoiceIds] = useState<Set<string>>(new Set())

  // Selection doesn't survive a filter/search change — same rule as Sales > Invoices' own
  // checkbox selection, avoids tracking selections against rows no longer in view.
  useEffect(() => {
    setSelectedInvoiceIds(new Set())
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    search,
    filters.customer_id,
    filters.status,
    filters.agingBucket,
    filters.dateFrom,
    filters.dateTo,
    filters.invoiceDateFrom,
    filters.invoiceDateTo,
    filters.branch_id,
    filters.sales_person_id,
  ])

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
      filters.branch_id,
      filters.sales_person_id,
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
        ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
        ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
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

  const hasFilters = !!(
    search ||
    filters.customer_id ||
    filters.status ||
    filters.agingBucket ||
    filters.dateFrom ||
    filters.dateTo ||
    filters.invoiceDateFrom ||
    filters.invoiceDateTo ||
    filters.branch_id ||
    filters.sales_person_id
  )

  const activeFilterParams = {
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.agingBucket ? { aging_bucket: filters.agingBucket } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
    ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
    ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
    ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
  }

  const printParams = new URLSearchParams(activeFilterParams).toString()

  const toggleInvoiceRow = (invoiceId: string | null) => {
    if (!invoiceId) return
    setSelectedInvoiceIds((prev) => {
      const next = new Set(prev)
      if (next.has(invoiceId)) next.delete(invoiceId)
      else next.add(invoiceId)
      return next
    })
  }

  // Scoped to the currently loaded page's rows — distinct from "select all" below, which covers
  // every row matching the active filters, not just what's on screen.
  const selectableRows = rows.filter((row) => row.invoice_id)
  const allVisibleSelected = selectableRows.length > 0 && selectableRows.every((row) => selectedInvoiceIds.has(row.invoice_id!))

  const [isSelectingAll, setIsSelectingAll] = useState(false)
  // "Select all" is explicitly required to respect active filters, not just the visible page —
  // fetches every filtered row via the same unpaginated endpoint the F1 Tanda Terima flow uses.
  const toggleAll = async () => {
    if (selectedInvoiceIds.size > 0) {
      setSelectedInvoiceIds(new Set())
      return
    }
    setIsSelectingAll(true)
    try {
      const allFiltered = await fetchAccountsReceivablesAll(activeFilterParams)
      setSelectedInvoiceIds(new Set(allFiltered.map((row) => row.invoice_id).filter((id): id is string => !!id)))
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsSelectingAll(false)
    }
  }

  const columns: DataTableColumn<AccountsReceivable>[] = [
    {
      id: 'select',
      header: (
        <Checkbox
          checked={allVisibleSelected}
          onCheckedChange={toggleAll}
          disabled={isSelectingAll}
          onClick={(e) => e.stopPropagation()}
          aria-label="Select all"
        />
      ),
      accessor: (row) => (
        <Checkbox
          checked={!!row.invoice_id && selectedInvoiceIds.has(row.invoice_id)}
          onCheckedChange={() => toggleInvoiceRow(row.invoice_id)}
          onClick={(e) => e.stopPropagation()}
          aria-label={`Select ${row.invoice?.document_number ?? row.id}`}
        />
      ),
      className: 'w-10',
    },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Branch', accessor: (row) => row.branch_name ?? '—' },
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

  const groupedQuery = useQuery({
    queryKey: ['ar-detail-grouped', activeFilterParams],
    queryFn: () => fetchAccountsReceivableGroupedDetail(activeFilterParams),
    enabled: viewMode === 'grouped',
    placeholderData: (previous) => previous,
  })

  const [isExporting, setIsExporting] = useState(false)
  // A non-empty selection overrides the active filters entirely (same rule as Sales > Invoices'
  // own checkbox-export flow) — never lossy, since checkboxes only ever appear on already-filtered rows.
  const exportReport = async (type: 'detail' | 'summary', format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportAccountsReceivables(
        selectedInvoiceIds.size > 0 ? { invoice_ids: [...selectedInvoiceIds] } : activeFilterParams,
        type,
        format,
      )
      downloadBlob(`Customer${type === 'detail' ? 'Detail' : 'Summary'}Aging.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="AR Detail"
        description="Every outstanding and settled receivable, by customer and invoice."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} receivables` : undefined}
        actions={
          <>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" disabled={isExporting}>
                  <Download className="size-4" />
                  Export CSV
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => exportReport('detail', 'csv')}>Detail</DropdownMenuItem>
                <DropdownMenuItem onClick={() => exportReport('summary', 'csv')}>Summary</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" disabled={isExporting}>
                  <Download className="size-4" />
                  Export XLSX
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => exportReport('detail', 'xlsx')}>Detail</DropdownMenuItem>
                <DropdownMenuItem onClick={() => exportReport('summary', 'xlsx')}>Summary</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
            <ActionBar
              actions={[
                { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
                {
                  label: 'Print',
                  icon: Printer,
                  onClick: () => navigate(`/reports/ar-detail/print${printParams ? `?${printParams}` : ''}`),
                },
                { label: 'Import', icon: Upload, disabled: true },
              ]}
            />
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button size="sm" variant={viewMode === 'aging' ? 'default' : 'ghost'} onClick={() => setViewMode('aging')}>
            Aging List
          </Button>
          <Button size="sm" variant={viewMode === 'grouped' ? 'default' : 'ghost'} onClick={() => setViewMode('grouped')}>
            Perincian Piutang
          </Button>
        </div>
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

      {viewMode === 'aging' ? (
        <>
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
        </>
      ) : !groupedQuery.data || groupedQuery.data.groups.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            {groupedQuery.isLoading ? 'Loading…' : 'No receivables match your filters.'}
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableBody>
                {groupedQuery.data.groups.map((salesPersonGroup) => (
                  <Fragment key={salesPersonGroup.sales_person_name}>
                    <TableRow className="bg-muted/50">
                      <TableCell colSpan={5} className="font-semibold">
                        Sales Person: {salesPersonGroup.sales_person_name}
                      </TableCell>
                    </TableRow>
                    {salesPersonGroup.customers.map((customerGroup) => {
                      const customerInvoiceIds = customerGroup.rows.map((row) => row.invoice_id).filter((id): id is string => !!id)
                      const customerAllSelected = customerInvoiceIds.length > 0 && customerInvoiceIds.every((id) => selectedInvoiceIds.has(id))
                      const toggleCustomer = () =>
                        setSelectedInvoiceIds((prev) => {
                          const next = new Set(prev)
                          customerInvoiceIds.forEach((id) => (customerAllSelected ? next.delete(id) : next.add(id)))
                          return next
                        })

                      return (
                      <Fragment key={customerGroup.customer_id}>
                        <TableRow className="bg-muted/20">
                          <TableCell colSpan={5} className="font-medium">
                            <div className="flex items-center gap-2">
                              <Checkbox
                                checked={customerAllSelected}
                                onCheckedChange={toggleCustomer}
                                disabled={customerInvoiceIds.length === 0}
                                aria-label={`Select all invoices for ${customerGroup.customer_name}`}
                              />
                              {customerGroup.customer_name}
                            </div>
                          </TableCell>
                        </TableRow>
                        <TableRow>
                          <TableCell>Document No</TableCell>
                          <TableCell>Date</TableCell>
                          <TableCell>Due Date</TableCell>
                          <TableCell className="text-right">Overdue Days</TableCell>
                          <TableCell className="text-right">Total Outstanding / Overdue Amount</TableCell>
                        </TableRow>
                        {customerGroup.rows.map((row, index) => (
                          <TableRow key={index}>
                            <TableCell>{row.document_no ?? '—'}</TableCell>
                            <TableCell>{row.date ? formatDate(row.date) : '—'}</TableCell>
                            <TableCell>{row.due_date ? formatDate(row.due_date) : '—'}</TableCell>
                            <TableCell className="text-right">{row.overdue_days > 0 ? row.overdue_days : '—'}</TableCell>
                            <TableCell className="text-right">
                              {formatCurrency(row.total_outstanding)}
                              {row.overdue_amount > 0 && (
                                <span className="ml-2 text-destructive">({formatCurrency(row.overdue_amount)})</span>
                              )}
                            </TableCell>
                          </TableRow>
                        ))}
                        <TableRow>
                          <TableCell colSpan={4} className="text-right font-medium">
                            Subtotal — {customerGroup.customer_name}
                          </TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(customerGroup.customer_subtotal)}</TableCell>
                        </TableRow>
                      </Fragment>
                      )
                    })}
                    <TableRow className="bg-muted/30">
                      <TableCell colSpan={4} className="text-right font-semibold">
                        Subtotal — Sales Person: {salesPersonGroup.sales_person_name}
                      </TableCell>
                      <TableCell className="text-right font-semibold">{formatCurrency(salesPersonGroup.sales_person_subtotal)}</TableCell>
                    </TableRow>
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </div>

          <Card>
            <CardContent className="flex items-center justify-end gap-2 py-4 text-base">
              <span className="text-muted-foreground">Grand Total</span>
              <span className="font-semibold">{formatCurrency(groupedQuery.data.grand_total)}</span>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
