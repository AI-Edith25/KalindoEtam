import { Fragment, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CalendarClock, ChevronDown, ChevronRight, Download, Landmark, Printer, RotateCw, TrendingDown, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { exportAccountsReceivables, fetchAccountsReceivables } from '@/features/payment/api/accountsReceivableApi'
import { fetchAccountsReceivablesAll } from '../api/accountsReceivableCustomerReportsApi'
import type { AccountsReceivable } from '@/features/payment/types'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { openPrintWindow } from '@/shared/lib/printOptions'
import { toastApiError } from '@/shared/services/errorHandler'
import { AccountsReceivableDetailReportFiltersBar } from '../components/AccountsReceivableDetailReportFiltersBar'
import { CustomerOutstandingArchiveFiltersBar } from '../components/CustomerOutstandingArchiveFiltersBar'
import { CustomerOutstandingArchiveImportDialog } from '../components/CustomerOutstandingArchiveImportDialog'
import { fetchAccountsReceivableGroupedDetail } from '../api/accountsReceivableGroupedDetailApi'
import { exportAccountsReceivableLedger, fetchAccountsReceivableLedger } from '../api/accountsReceivableLedgerApi'
import {
  exportCustomerOutstandingArchive,
  fetchCustomerOutstandingArchiveDetail,
  fetchCustomerOutstandingSnapshots,
} from '../api/customerOutstandingArchiveApi'
import { resolveJournalReferenceLink } from '@/features/accounting/lib/journalReferenceLink'
import { emptyArDetailReportFilters, emptyCustomerOutstandingArchiveFilters } from '../lib/reportFilters'
import { flattenToAgingRows, groupByAgingBucket } from '../lib/customerOutstandingArchiveAging'
import type { ArDetailReportFilterValues, CustomerOutstandingArchiveFilterValues } from '../types'

type ViewMode = 'aging' | 'grouped' | 'ledger' | 'archive'

/**
 * Reads live Accounts Receivable data when there is any; falls back to the imported Customer
 * Outstanding Bills snapshot for Aging List / Perincian Piutang / Kartu Piutang whenever the
 * live table is completely empty (the two are never mixed in one table). See
 * CustomerOutstandingArchiveImportService's own docblock for the snapshot's own parsing rules.
 */
export function AccountsReceivableDetailReportPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ArDetailReportFilterValues>(emptyArDetailReportFilters)
  const [viewMode, setViewMode] = useState<ViewMode>('aging')
  const [ledgerPage, setLedgerPage] = useState(1)
  const [selectedInvoiceIds, setSelectedInvoiceIds] = useState<Set<string>>(new Set())

  // Unfiltered, fired once — the single source of truth for "does the live AR table have any
  // row at all", independent of whatever the active table filters currently narrow down to.
  // A filtered search legitimately returning 0 rows must never be confused with "the live table
  // is empty", which is the actual condition that switches the whole page to snapshot mode.
  const liveDataCheckQuery = useQuery({
    queryKey: ['ar-detail-has-live-data'],
    queryFn: () => fetchAccountsReceivables({ page: 1, per_page: 1 }),
    staleTime: 60_000,
  })
  const liveDataChecked = !liveDataCheckQuery.isLoading
  const hasLiveData = (liveDataCheckQuery.data?.meta.total ?? 0) > 0
  const snapshotMode = liveDataChecked && !hasLiveData

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
    enabled: viewMode === 'aging' && hasLiveData,
    placeholderData: (previous) => previous,
  })

  const allRows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])
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

  const selectableRows = rows.filter((row) => row.invoice_id)
  const allVisibleSelected = selectableRows.length > 0 && selectableRows.every((row) => selectedInvoiceIds.has(row.invoice_id!))

  const [isSelectingAll, setIsSelectingAll] = useState(false)
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
    enabled: viewMode === 'grouped' && hasLiveData,
    placeholderData: (previous) => previous,
  })

  const ledgerQuery = useQuery({
    queryKey: ['ar-ledger', filters.customer_id, filters.invoiceDateFrom, filters.invoiceDateTo, ledgerPage],
    queryFn: () =>
      fetchAccountsReceivableLedger({
        customer_id: filters.customer_id,
        ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
        ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
        page: ledgerPage,
      }),
    enabled: viewMode === 'ledger' && hasLiveData && !!filters.customer_id,
    placeholderData: (previous) => previous,
  })

  useEffect(() => {
    setLedgerPage(1)
  }, [filters.customer_id, filters.invoiceDateFrom, filters.invoiceDateTo])

  const [isExporting, setIsExporting] = useState(false)
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

  const exportLedger = async (format: 'xlsx' | 'csv') => {
    if (!filters.customer_id) return
    setIsExporting(true)
    try {
      const blob = await exportAccountsReceivableLedger(
        {
          customer_id: filters.customer_id,
          ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
          ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
        },
        format,
      )
      downloadBlob(`KartuPiutang.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  // Customer Outstanding Bills archive — see CustomerOutstandingArchiveImportService's own
  // docblock for why it's a completely standalone backend pipeline. In snapshot mode it also
  // becomes the data source for Aging List / Perincian Piutang / Kartu Piutang below.
  const canViewArchive = useHasPermission('reports.ar_archive.view')
  const canImportArchive = useHasPermission('reports.ar_archive.import')
  const [archiveImportOpen, setArchiveImportOpen] = useState(false)
  const [archiveHistoryOpen, setArchiveHistoryOpen] = useState(false)
  const [expandedCustomers, setExpandedCustomers] = useState<Set<string>>(new Set())
  const [selectedSnapshotId, setSelectedSnapshotId] = useState<string | null>(null)
  const [archiveFilters, setArchiveFilters] = useState<CustomerOutstandingArchiveFilterValues>(emptyCustomerOutstandingArchiveFilters)

  const snapshotsQuery = useQuery({
    queryKey: ['customer-outstanding-snapshots'],
    queryFn: fetchCustomerOutstandingSnapshots,
    enabled: canViewArchive,
  })

  // Most-recently-imported snapshot is the active one — snapshots() is already ordered that way.
  useEffect(() => {
    if (selectedSnapshotId === null && snapshotsQuery.data && snapshotsQuery.data.length > 0) {
      setSelectedSnapshotId(snapshotsQuery.data[0].id)
    }
  }, [snapshotsQuery.data, selectedSnapshotId])

  // Fetched whenever the "Customer Outstanding Bills" tab itself is open, OR whenever the page
  // is in snapshot mode (the live table is empty) regardless of which of the 4 tabs is active —
  // Aging List/Perincian Piutang/Kartu Piutang all read this same query's data in that case.
  const archiveDetailQuery = useQuery({
    queryKey: ['customer-outstanding-archive-detail', selectedSnapshotId, archiveFilters],
    queryFn: () => fetchCustomerOutstandingArchiveDetail(selectedSnapshotId as string, archiveFilters),
    enabled: !!selectedSnapshotId && (viewMode === 'archive' || snapshotMode),
    placeholderData: (previous) => previous,
  })

  const selectedSnapshot = snapshotsQuery.data?.find((s) => s.id === selectedSnapshotId) ?? archiveDetailQuery.data?.snapshot

  const exportArchive = async (format: 'xlsx' | 'csv') => {
    if (!selectedSnapshotId) return
    setIsExporting(true)
    try {
      const blob = await exportCustomerOutstandingArchive(selectedSnapshotId, archiveFilters, format)
      downloadBlob(`PiutangCustomerArsip.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const agingRows = useMemo(() => (archiveDetailQuery.data ? flattenToAgingRows(archiveDetailQuery.data.customers) : []), [archiveDetailQuery.data])
  const agingBuckets = useMemo(() => groupByAgingBucket(agingRows), [agingRows])
  const agingGrandTotal = agingRows.reduce((sum, row) => sum + row.unpaid_amount, 0)

  const toggleCustomerExpanded = (code: string) =>
    setExpandedCustomers((prev) => {
      const next = new Set(prev)
      if (next.has(code)) next.delete(code)
      else next.add(code)
      return next
    })

  // Kartu Piutang has no payment date/receipt number in the source file at all — a real mutation
  // ledger isn't possible. What IS derivable: a per-customer chronological invoice list with a
  // running balance (Debit = Invoice Amt, Kredit = Paid Amount), same customer text filter the
  // other snapshot views use, matched as an exact code first, else falling back to a unique name match.
  const ledgerCustomer = useMemo(() => {
    if (!archiveDetailQuery.data || !archiveFilters.customer) return null
    const needle = archiveFilters.customer.trim().toLowerCase()
    return (
      archiveDetailQuery.data.customers.find((c) => c.customer_code.toLowerCase() === needle) ??
      archiveDetailQuery.data.customers.find((c) => c.customer_name.toLowerCase() === needle) ??
      null
    )
  }, [archiveDetailQuery.data, archiveFilters.customer])

  const snapshotBanner = snapshotMode && selectedSnapshot && (
    <Alert>
      <AlertTitle>Sumber: import manual per {formatDate(selectedSnapshot.snapshot_as_of_date)} — bukan data live.</AlertTitle>
    </Alert>
  )

  const noSnapshotEmptyState = (
    <Card>
      <CardContent className="flex flex-col items-center gap-3 py-10 text-center text-muted-foreground">
        <p>Belum ada data piutang. Import file Customer Unpaid Bills dari Skybiz melalui tombol Import di kanan atas.</p>
        {canImportArchive && (
          <Button type="button" onClick={() => setArchiveImportOpen(true)}>
            <Upload className="size-4" />
            Import
          </Button>
        )}
      </CardContent>
    </Card>
  )

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="AR Detail"
        description={
          snapshotMode && selectedSnapshot
            ? `Piutang yang belum lunas per ${formatDate(selectedSnapshot.snapshot_as_of_date)}.`
            : 'Every outstanding and settled receivable, by customer and invoice.'
        }
        count={
          viewMode === 'archive' || snapshotMode
            ? archiveDetailQuery.data
              ? `${formatNumber(archiveDetailQuery.data.customers.length)} customer`
              : undefined
            : listQuery.data?.meta
              ? `${formatNumber(listQuery.data.meta.total)} receivables`
              : undefined
        }
        actions={
          viewMode === 'ledger' && !snapshotMode ? (
            <>
              <Button variant="outline" disabled={isExporting || !filters.customer_id} onClick={() => exportLedger('csv')}>
                <Download className="size-4" />
                Export CSV
              </Button>
              <Button variant="outline" disabled={isExporting || !filters.customer_id} onClick={() => exportLedger('xlsx')}>
                <Download className="size-4" />
                Export XLSX
              </Button>
              <ActionBar
                actions={[
                  { label: 'Refresh', icon: RotateCw, onClick: () => ledgerQuery.refetch(), disabled: ledgerQuery.isFetching },
                  {
                    label: 'Print',
                    icon: Printer,
                    disabled: !filters.customer_id,
                    onClick: () =>
                      openPrintWindow(
                        `/reports/ar-detail/statement-print?${new URLSearchParams({
                          customer_id: filters.customer_id,
                          ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
                          ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
                        }).toString()}`,
                      ),
                  },
                ]}
              />
            </>
          ) : viewMode === 'archive' || snapshotMode ? (
            <>
              <Button variant="outline" disabled={isExporting || !selectedSnapshotId} onClick={() => exportArchive('csv')}>
                <Download className="size-4" />
                Export CSV
              </Button>
              <Button variant="outline" disabled={isExporting || !selectedSnapshotId} onClick={() => exportArchive('xlsx')}>
                <Download className="size-4" />
                Export XLSX
              </Button>
              <ActionBar
                actions={[
                  { label: 'Refresh', icon: RotateCw, onClick: () => archiveDetailQuery.refetch(), disabled: archiveDetailQuery.isFetching },
                  { label: 'Print', icon: Printer, disabled: true },
                ]}
                primary={canImportArchive ? { label: 'Import Data', icon: Upload, onClick: () => setArchiveImportOpen(true) } : undefined}
              />
            </>
          ) : (
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
                    onClick: () => openPrintWindow(`/reports/ar-detail/print${printParams ? `?${printParams}` : ''}`),
                  },
                  { label: 'Import', icon: Upload, disabled: true },
                ]}
              />
            </>
          )
        }
      />

      {snapshotBanner}

      {snapshotMode && archiveDetailQuery.data && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {/* Deliberately the FILTERED grand_total_unpaid, not the unfiltered summary.total_unpaid --
              this must read identical to Aging List's Grand Total and Customer Outstanding Bills'
              Grand Total Unpaid whenever the same filters are active. */}
          <SummaryCard title="Total Piutang" value={formatCurrency(archiveDetailQuery.data.grand_total_unpaid)} icon={Landmark} />
          <SummaryCard
            title="Jatuh Tempo Minggu Ini"
            value={formatCurrency(archiveDetailQuery.data.summary.due_this_week)}
            icon={CalendarClock}
            tone="warning"
          />
          <SummaryCard
            title="Sudah Lewat Jatuh Tempo"
            value={formatCurrency(archiveDetailQuery.data.summary.overdue)}
            icon={TrendingDown}
            tone="danger"
          />
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button size="sm" variant={viewMode === 'aging' ? 'default' : 'ghost'} onClick={() => setViewMode('aging')}>
            Aging List
          </Button>
          <Button size="sm" variant={viewMode === 'grouped' ? 'default' : 'ghost'} onClick={() => setViewMode('grouped')}>
            Perincian Piutang
          </Button>
          <Button size="sm" variant={viewMode === 'ledger' ? 'default' : 'ghost'} onClick={() => setViewMode('ledger')}>
            Kartu Piutang
          </Button>
          {canViewArchive && (
            <Button size="sm" variant={viewMode === 'archive' ? 'default' : 'ghost'} onClick={() => setViewMode('archive')}>
              Customer Outstanding Bills
            </Button>
          )}
        </div>
        {viewMode === 'archive' || snapshotMode ? (
          <>
            <div className="flex flex-col gap-1.5">
              <span className="text-xs text-muted-foreground">Snapshot / As Of Date</span>
              <Select value={selectedSnapshotId ?? undefined} onValueChange={setSelectedSnapshotId}>
                <SelectTrigger className="w-64">
                  <SelectValue placeholder={snapshotsQuery.isLoading ? 'Memuat…' : 'Pilih snapshot…'} />
                </SelectTrigger>
                <SelectContent>
                  {(snapshotsQuery.data ?? []).map((snapshot) => (
                    <SelectItem key={snapshot.id} value={snapshot.id}>
                      {formatDate(snapshot.snapshot_as_of_date)} — {snapshot.source_filename}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <CustomerOutstandingArchiveFiltersBar value={archiveFilters} onChange={setArchiveFilters} />
          </>
        ) : (
          <>
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
          </>
        )}
      </div>

      {viewMode === 'archive' && (
        <Card>
          <button
            type="button"
            onClick={() => setArchiveHistoryOpen((v) => !v)}
            className="flex w-full items-center gap-2 p-3 text-left text-sm font-medium"
          >
            {archiveHistoryOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
            Riwayat Import
          </button>
          {archiveHistoryOpen && (
            <CardContent className="pt-0">
              <div className="overflow-x-auto rounded-md border">
                <Table>
                  <TableBody>
                    <TableRow className="bg-muted/50 font-medium">
                      <TableCell>File</TableCell>
                      <TableCell>Waktu Import</TableCell>
                      <TableCell>Snapshot Per</TableCell>
                      <TableCell className="text-right">Baris</TableCell>
                      <TableCell className="text-right">Total Outstanding</TableCell>
                      <TableCell>Diimpor Oleh</TableCell>
                    </TableRow>
                    {(snapshotsQuery.data ?? []).map((snapshot) => (
                      <TableRow key={snapshot.id}>
                        <TableCell>{snapshot.source_filename}</TableCell>
                        <TableCell>{formatDate(snapshot.created_at)}</TableCell>
                        <TableCell>{formatDate(snapshot.snapshot_as_of_date)}</TableCell>
                        <TableCell className="text-right">{formatNumber(snapshot.total_rows)}</TableCell>
                        <TableCell className="text-right">{formatCurrency(snapshot.grand_total_unpaid)}</TableCell>
                        <TableCell>{snapshot.importer?.name ?? '—'}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          )}
        </Card>
      )}

      {!liveDataChecked ? (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">Loading…</CardContent>
        </Card>
      ) : viewMode === 'aging' ? (
        snapshotMode ? (
          !selectedSnapshotId ? (
            noSnapshotEmptyState
          ) : (
            <>
              <div className="overflow-x-auto rounded-md border">
                <Table>
                  <TableBody>
                    <TableRow className="bg-muted/50 text-xs">
                      <TableCell>Customer</TableCell>
                      <TableCell>Branch</TableCell>
                      <TableCell>Sales Person</TableCell>
                      <TableCell>Invoice Number</TableCell>
                      <TableCell>Invoice Date</TableCell>
                      <TableCell>Masa</TableCell>
                      <TableCell>Umur</TableCell>
                      <TableCell>Due Date</TableCell>
                      <TableCell className="text-right">Total Invoice</TableCell>
                      <TableCell className="text-right">Paid Amount</TableCell>
                      <TableCell className="text-right">Outstanding Amount</TableCell>
                      <TableCell>Status</TableCell>
                    </TableRow>
                    {agingBuckets.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={12} className="py-8 text-center text-muted-foreground">
                          {archiveDetailQuery.isLoading ? 'Loading…' : 'Tidak ada data yang cocok dengan filter.'}
                        </TableCell>
                      </TableRow>
                    )}
                    {agingBuckets.map((bucket) => (
                      <Fragment key={bucket.key}>
                        <TableRow className="bg-muted/20">
                          <TableCell colSpan={12} className="font-medium">{bucket.label}</TableCell>
                        </TableRow>
                        {bucket.rows.map((row) => (
                          <TableRow key={row.id}>
                            <TableCell>{row.customer_name}</TableCell>
                            <TableCell>—</TableCell>
                            <TableCell>—</TableCell>
                            <TableCell>{row.ref_no}</TableCell>
                            <TableCell>{formatDate(row.txn_date)}</TableCell>
                            <TableCell>{row.terms_days !== null ? `${row.terms_days} hari` : '—'}</TableCell>
                            <TableCell>{row.overdue_days > 0 ? `${row.overdue_days} hari` : '—'}</TableCell>
                            <TableCell>{formatDate(row.due_date)}</TableCell>
                            <TableCell className="text-right">{formatCurrency(row.invoice_amount)}</TableCell>
                            <TableCell className="text-right">{formatCurrency(row.paid_amount)}</TableCell>
                            <TableCell className="text-right">{formatCurrency(row.unpaid_amount)}</TableCell>
                            <TableCell><StatusBadge status={row.status} /></TableCell>
                          </TableRow>
                        ))}
                        <TableRow>
                          <TableCell colSpan={10} className="text-right font-medium">Subtotal — {bucket.label}</TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(bucket.subtotal)}</TableCell>
                          <TableCell />
                        </TableRow>
                      </Fragment>
                    ))}
                  </TableBody>
                </Table>
              </div>
              {agingBuckets.length > 0 && (
                <Card>
                  <CardContent className="flex items-center justify-end gap-2 py-4 text-base">
                    <span className="text-muted-foreground">Grand Total</span>
                    <span className="font-semibold">{formatCurrency(agingGrandTotal)}</span>
                  </CardContent>
                </Card>
              )}
            </>
          )
        ) : (
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
        )
      ) : viewMode === 'ledger' ? (
        snapshotMode ? (
          !selectedSnapshotId ? (
            noSnapshotEmptyState
          ) : !ledgerCustomer ? (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                Isi filter Customer di atas dengan kode atau nama customer (persis) untuk melihat Kartu Piutang.
              </CardContent>
            </Card>
          ) : (
            <>
              <Alert>
                <AlertDescription>
                  Rincian pembayaran (tanggal bayar, nomor bukti) tidak tersedia pada data import — hanya daftar invoice kronologis dengan saldo
                  berjalan yang ditampilkan.
                </AlertDescription>
              </Alert>
              <div className="overflow-x-auto rounded-md border">
                <Table>
                  <TableBody>
                    <TableRow className="bg-muted/50 font-medium">
                      <TableCell>Tanggal</TableCell>
                      <TableCell>Ref. No</TableCell>
                      <TableCell>Tanggal Bayar</TableCell>
                      <TableCell>No. Bukti</TableCell>
                      <TableCell className="text-right">Debit</TableCell>
                      <TableCell className="text-right">Kredit</TableCell>
                      <TableCell className="text-right">Saldo Berjalan</TableCell>
                    </TableRow>
                    {(() => {
                      let balance = 0
                      return [...ledgerCustomer.rows]
                        .sort((a, b) => a.txn_date.localeCompare(b.txn_date))
                        .map((row) => {
                          balance += row.invoice_amount - row.paid_amount
                          return (
                            <TableRow key={row.id}>
                              <TableCell>{formatDate(row.txn_date)}</TableCell>
                              <TableCell>{row.ref_no}</TableCell>
                              <TableCell>—</TableCell>
                              <TableCell>—</TableCell>
                              <TableCell className="text-right">{formatCurrency(row.invoice_amount)}</TableCell>
                              <TableCell className="text-right">{row.paid_amount ? formatCurrency(row.paid_amount) : '—'}</TableCell>
                              <TableCell className="text-right">{formatCurrency(balance)}</TableCell>
                            </TableRow>
                          )
                        })
                    })()}
                    <TableRow className="bg-muted/50 font-semibold">
                      <TableCell colSpan={6}>Saldo Akhir</TableCell>
                      <TableCell className="text-right">{formatCurrency(ledgerCustomer.subtotal_unpaid)}</TableCell>
                    </TableRow>
                  </TableBody>
                </Table>
              </div>
            </>
          )
        ) : !filters.customer_id ? (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">Pilih pelanggan untuk melihat Kartu Piutang.</CardContent>
          </Card>
        ) : !ledgerQuery.data ? (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">
              {ledgerQuery.isLoading ? 'Loading…' : 'Gagal memuat Kartu Piutang.'}
            </CardContent>
          </Card>
        ) : (
          <>
            <Card>
              <CardContent className="grid grid-cols-2 gap-x-6 gap-y-1 py-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
                <div>
                  <p className="text-xs text-muted-foreground">Nama Pelanggan</p>
                  <p className="font-medium">{ledgerQuery.data.data.header.customer_name}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Kode</p>
                  <p className="font-medium">{ledgerQuery.data.data.header.customer_code}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Branch</p>
                  <p className="font-medium">{ledgerQuery.data.data.header.branch_name ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Sales Person</p>
                  <p className="font-medium">{ledgerQuery.data.data.header.sales_person_name ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Terms of Payment</p>
                  <p className="font-medium">{ledgerQuery.data.data.header.terms_of_payment_name ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Saldo Akhir</p>
                  <p className="font-semibold">{formatCurrency(ledgerQuery.data.data.closing_balance)}</p>
                </div>
              </CardContent>
            </Card>

            <div className="overflow-x-auto rounded-md border">
              <Table>
                <TableBody>
                  <TableRow className="bg-muted/50 font-medium">
                    <TableCell>Tanggal</TableCell>
                    <TableCell>Jenis Dokumen</TableCell>
                    <TableCell>Nomor Dokumen</TableCell>
                    <TableCell>Keterangan</TableCell>
                    <TableCell>Jatuh Tempo</TableCell>
                    <TableCell className="text-right">Debit</TableCell>
                    <TableCell className="text-right">Kredit</TableCell>
                    <TableCell className="text-right">Saldo Berjalan</TableCell>
                  </TableRow>
                  {ledgerQuery.data.meta.current_page === 1 && (
                    <TableRow className="bg-muted/20">
                      <TableCell colSpan={7} className="font-medium">
                        Saldo Awal
                      </TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(ledgerQuery.data.data.opening_balance)}</TableCell>
                    </TableRow>
                  )}
                  {ledgerQuery.data.data.rows.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                        Tidak ada mutasi pada periode ini.
                      </TableCell>
                    </TableRow>
                  )}
                  {ledgerQuery.data.data.rows.map((row, index) => {
                    const link = row.reference_id ? resolveJournalReferenceLink(row.document_type, row.reference_id) : null

                    return (
                      <TableRow key={index}>
                        <TableCell>{formatDate(row.date)}</TableCell>
                        <TableCell>{row.document_type}</TableCell>
                        <TableCell>
                          {link ? (
                            <Link to={link} className="text-primary underline-offset-2 hover:underline">
                              {row.document_number ?? '—'}
                            </Link>
                          ) : (
                            (row.document_number ?? '—')
                          )}
                        </TableCell>
                        <TableCell>{row.description ?? '—'}</TableCell>
                        <TableCell>{row.due_date ? formatDate(row.due_date) : '—'}</TableCell>
                        <TableCell className="text-right">{row.debit ? formatCurrency(row.debit) : '—'}</TableCell>
                        <TableCell className="text-right">{row.credit ? formatCurrency(row.credit) : '—'}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.running_balance)}</TableCell>
                      </TableRow>
                    )
                  })}
                  {ledgerQuery.data.meta.current_page === ledgerQuery.data.meta.last_page && (
                    <TableRow className="bg-muted/50 font-semibold">
                      <TableCell colSpan={7}>Saldo Akhir</TableCell>
                      <TableCell className="text-right">{formatCurrency(ledgerQuery.data.data.closing_balance)}</TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>

            <Pagination meta={ledgerQuery.data.meta} onPageChange={setLedgerPage} />

            <Card>
              <CardContent className="grid grid-cols-2 gap-4 py-4 text-sm sm:grid-cols-5">
                <div>
                  <p className="text-xs text-muted-foreground">Belum Jatuh Tempo</p>
                  <p className="font-medium">{formatCurrency(ledgerQuery.data.data.aging.not_due)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">1-30 Hari</p>
                  <p className="font-medium">{formatCurrency(ledgerQuery.data.data.aging.due_1_30)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">31-60 Hari</p>
                  <p className="font-medium">{formatCurrency(ledgerQuery.data.data.aging.due_31_60)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">61-90 Hari</p>
                  <p className="font-medium text-destructive">{formatCurrency(ledgerQuery.data.data.aging.due_61_90)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">&gt; 90 Hari</p>
                  <p className="font-medium text-destructive">{formatCurrency(ledgerQuery.data.data.aging.due_over_90)}</p>
                </div>
              </CardContent>
            </Card>
          </>
        )
      ) : viewMode === 'archive' ? (
        !selectedSnapshotId ? (
          noSnapshotEmptyState
        ) : !archiveDetailQuery.data || archiveDetailQuery.data.customers.length === 0 ? (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">
              {archiveDetailQuery.isLoading ? 'Loading…' : 'Tidak ada data yang cocok dengan filter.'}
            </CardContent>
          </Card>
        ) : (
          <>
            <div className="overflow-x-auto rounded-md border">
              <Table>
                <TableBody>
                  {archiveDetailQuery.data.customers.map((customer) => (
                    <Fragment key={customer.customer_code}>
                      <TableRow className="bg-muted/20">
                        <TableCell colSpan={8} className="font-medium">
                          {customer.customer_code} — {customer.customer_name}
                        </TableCell>
                      </TableRow>
                      <TableRow className="bg-muted/50 text-xs">
                        <TableCell>Date</TableCell>
                        <TableCell>Ref. No</TableCell>
                        <TableCell className="text-right">Invoice Amt</TableCell>
                        <TableCell className="text-right">Paid</TableCell>
                        <TableCell className="text-right">Unpaid</TableCell>
                        <TableCell>Due Date</TableCell>
                        <TableCell className="text-right">Overdue Amt</TableCell>
                        <TableCell>Status</TableCell>
                      </TableRow>
                      {customer.rows.map((row) => (
                        <TableRow key={row.id}>
                          <TableCell>{formatDate(row.txn_date)}</TableCell>
                          <TableCell>{row.ref_no}</TableCell>
                          <TableCell className="text-right">{formatCurrency(row.invoice_amount)}</TableCell>
                          <TableCell className="text-right">{formatCurrency(row.paid_amount)}</TableCell>
                          <TableCell className="text-right">{formatCurrency(row.unpaid_amount)}</TableCell>
                          <TableCell>{formatDate(row.due_date)}</TableCell>
                          <TableCell className="text-right">
                            {formatCurrency(row.overdue_amount)}
                            {row.overdue_days > 0 && <span className="ml-1 text-xs text-muted-foreground">({row.overdue_days}d)</span>}
                          </TableCell>
                          <TableCell><StatusBadge status={row.status} /></TableCell>
                        </TableRow>
                      ))}
                      <TableRow>
                        <TableCell colSpan={4} className="text-right font-medium">
                          Subtotal — {customer.customer_name}
                        </TableCell>
                        <TableCell className="text-right font-medium">{formatCurrency(customer.subtotal_unpaid)}</TableCell>
                        <TableCell colSpan={2} />
                        <TableCell className="text-right font-medium">{formatCurrency(customer.subtotal_overdue)}</TableCell>
                      </TableRow>
                    </Fragment>
                  ))}
                </TableBody>
              </Table>
            </div>

            <Card>
              <CardContent className="flex flex-wrap items-center justify-end gap-6 py-4 text-base">
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground">Grand Total Unpaid</span>
                  <span className="font-semibold">{formatCurrency(archiveDetailQuery.data.grand_total_unpaid)}</span>
                </span>
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground">Grand Total Overdue</span>
                  <span className="font-semibold">{formatCurrency(archiveDetailQuery.data.grand_total_overdue)}</span>
                </span>
              </CardContent>
            </Card>
          </>
        )
      ) : // viewMode === 'grouped' (Perincian Piutang)
      snapshotMode ? (
        !selectedSnapshotId ? (
          noSnapshotEmptyState
        ) : !archiveDetailQuery.data || archiveDetailQuery.data.customers.length === 0 ? (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">
              {archiveDetailQuery.isLoading ? 'Loading…' : 'Tidak ada data yang cocok dengan filter.'}
            </CardContent>
          </Card>
        ) : (
          <>
            <div className="overflow-x-auto rounded-md border">
              <Table>
                <TableBody>
                  <TableRow className="bg-muted/50 font-medium">
                    <TableCell>Customer</TableCell>
                    <TableCell className="text-right">Jumlah Invoice</TableCell>
                    <TableCell className="text-right">Total Invoice</TableCell>
                    <TableCell className="text-right">Total Paid</TableCell>
                    <TableCell className="text-right">Total Outstanding</TableCell>
                    <TableCell className="text-right">Total Overdue</TableCell>
                  </TableRow>
                  {archiveDetailQuery.data.customers.map((customer) => {
                    const expanded = expandedCustomers.has(customer.customer_code)
                    const totalInvoice = customer.rows.reduce((sum, r) => sum + r.invoice_amount, 0)
                    const totalPaid = customer.rows.reduce((sum, r) => sum + r.paid_amount, 0)

                    return (
                      <Fragment key={customer.customer_code}>
                        <TableRow className="cursor-pointer hover:bg-muted/30" onClick={() => toggleCustomerExpanded(customer.customer_code)}>
                          <TableCell className="flex items-center gap-2 font-medium">
                            {expanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                            {customer.customer_code} — {customer.customer_name}
                          </TableCell>
                          <TableCell className="text-right">{formatNumber(customer.rows.length)}</TableCell>
                          <TableCell className="text-right">{formatCurrency(totalInvoice)}</TableCell>
                          <TableCell className="text-right">{formatCurrency(totalPaid)}</TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(customer.subtotal_unpaid)}</TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(customer.subtotal_overdue)}</TableCell>
                        </TableRow>
                        {expanded &&
                          customer.rows.map((row) => (
                            <TableRow key={row.id} className="text-sm text-muted-foreground">
                              <TableCell className="pl-8">{row.ref_no}</TableCell>
                              <TableCell className="text-right">{formatDate(row.txn_date)}</TableCell>
                              <TableCell className="text-right">{formatCurrency(row.invoice_amount)}</TableCell>
                              <TableCell className="text-right">{formatCurrency(row.paid_amount)}</TableCell>
                              <TableCell className="text-right">{formatCurrency(row.unpaid_amount)}</TableCell>
                              <TableCell className="text-right">{formatCurrency(row.overdue_amount)}</TableCell>
                            </TableRow>
                          ))}
                      </Fragment>
                    )
                  })}
                </TableBody>
              </Table>
            </div>

            <Card>
              <CardContent className="flex flex-wrap items-center justify-end gap-6 py-4 text-base">
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground">Grand Total Outstanding</span>
                  <span className="font-semibold">{formatCurrency(archiveDetailQuery.data.grand_total_unpaid)}</span>
                </span>
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground">Grand Total Overdue</span>
                  <span className="font-semibold">{formatCurrency(archiveDetailQuery.data.grand_total_overdue)}</span>
                </span>
              </CardContent>
            </Card>
          </>
        )
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

      <CustomerOutstandingArchiveImportDialog
        open={archiveImportOpen}
        onClose={() => setArchiveImportOpen(false)}
        onImported={(snapshot) => setSelectedSnapshotId(snapshot.id)}
      />
    </div>
  )
}
