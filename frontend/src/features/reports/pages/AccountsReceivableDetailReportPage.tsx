import { Fragment, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, Download, Printer, RotateCw, Upload } from 'lucide-react'
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
import type { ArDetailReportFilterValues, CustomerOutstandingArchiveFilterValues } from '../types'

type ViewMode = 'aging' | 'grouped' | 'ledger' | 'archive'

/** Read-only report over Accounts Receivable — reuses fetchAccountsReceivables() as-is, no new endpoint. */
export function AccountsReceivableDetailReportPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ArDetailReportFilterValues>(emptyArDetailReportFilters)
  const [viewMode, setViewMode] = useState<ViewMode>('aging')
  // Independent from the Aging List's own `page` — Kartu Piutang is a different table entirely.
  const [ledgerPage, setLedgerPage] = useState(1)
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

  // Kartu Piutang requires a customer first — no query fires (and no empty-table flash) until one is picked.
  const ledgerQuery = useQuery({
    queryKey: ['ar-ledger', filters.customer_id, filters.invoiceDateFrom, filters.invoiceDateTo, ledgerPage],
    queryFn: () =>
      fetchAccountsReceivableLedger({
        customer_id: filters.customer_id,
        ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
        ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
        page: ledgerPage,
      }),
    enabled: viewMode === 'ledger' && !!filters.customer_id,
    placeholderData: (previous) => previous,
  })

  useEffect(() => {
    setLedgerPage(1)
  }, [filters.customer_id, filters.invoiceDateFrom, filters.invoiceDateTo])

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

  // Customer Outstanding Bills — a standalone archive of imported legacy AR export snapshots,
  // never joined to the accounts-receivables data this page otherwise shows. See
  // CustomerOutstandingArchiveImportService's own docblock for why it's kept separate on the
  // backend despite living as a tab here.
  const canViewArchive = useHasPermission('reports.ar_archive.view')
  const canImportArchive = useHasPermission('reports.ar_archive.import')
  const [archiveImportOpen, setArchiveImportOpen] = useState(false)
  const [archiveHistoryOpen, setArchiveHistoryOpen] = useState(false)
  const [selectedSnapshotId, setSelectedSnapshotId] = useState<string | null>(null)
  const [archiveFilters, setArchiveFilters] = useState<CustomerOutstandingArchiveFilterValues>(emptyCustomerOutstandingArchiveFilters)

  const snapshotsQuery = useQuery({
    queryKey: ['customer-outstanding-snapshots'],
    queryFn: fetchCustomerOutstandingSnapshots,
    enabled: canViewArchive,
  })

  useEffect(() => {
    if (selectedSnapshotId === null && snapshotsQuery.data && snapshotsQuery.data.length > 0) {
      setSelectedSnapshotId(snapshotsQuery.data[0].id)
    }
  }, [snapshotsQuery.data, selectedSnapshotId])

  const archiveDetailQuery = useQuery({
    queryKey: ['customer-outstanding-archive-detail', selectedSnapshotId, archiveFilters],
    queryFn: () => fetchCustomerOutstandingArchiveDetail(selectedSnapshotId as string, archiveFilters),
    enabled: viewMode === 'archive' && !!selectedSnapshotId,
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

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="AR Detail"
        description="Every outstanding and settled receivable, by customer and invoice."
        count={
          viewMode === 'archive'
            ? archiveDetailQuery.data
              ? `${formatNumber(archiveDetailQuery.data.customers.length)} customer`
              : undefined
            : listQuery.data?.meta
              ? `${formatNumber(listQuery.data.meta.total)} receivables`
              : undefined
        }
        actions={
          viewMode === 'ledger' ? (
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
          ) : viewMode === 'archive' ? (
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
                actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => archiveDetailQuery.refetch(), disabled: archiveDetailQuery.isFetching }]}
                primary={{ label: 'Import Data', icon: Upload, disabled: !canImportArchive, onClick: () => setArchiveImportOpen(true) }}
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
        {viewMode === 'archive' ? (
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
        <>
          <Card>
            <CardContent className="py-3 text-sm text-muted-foreground">
              Data import manual — bukan data live. Snapshot per {selectedSnapshot ? formatDate(selectedSnapshot.snapshot_as_of_date) : '—'}.
            </CardContent>
          </Card>

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
                        <TableCell className="text-right">Baris</TableCell>
                        <TableCell className="text-right">Customer</TableCell>
                        <TableCell>Diimpor Oleh</TableCell>
                      </TableRow>
                      {(snapshotsQuery.data ?? []).map((snapshot) => (
                        <TableRow key={snapshot.id}>
                          <TableCell>{snapshot.source_filename}</TableCell>
                          <TableCell>{formatDate(snapshot.created_at)}</TableCell>
                          <TableCell className="text-right">{formatNumber(snapshot.total_rows)}</TableCell>
                          <TableCell className="text-right">{formatNumber(snapshot.total_customers)}</TableCell>
                          <TableCell>{snapshot.importer?.name ?? '—'}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            )}
          </Card>
        </>
      )}

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
      ) : viewMode === 'ledger' ? (
        !filters.customer_id ? (
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
                  {/* Only the table's actual first row (page 1) claims to be "Saldo Awal" — on
                      later pages the running balance already continues from wherever the prior
                      page left off, so repeating the original opening balance here would sit
                      right above a Saldo Berjalan that doesn't start from it, even though the
                      math (walked from the true opening balance across the whole period) is
                      correct. */}
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
                  {/* Only the table's actual last row (last page) claims to be "Saldo Akhir" — on
                      earlier pages the true closing balance is already visible in the header card
                      above, so pinning it here too would sit under a Saldo Berjalan that hasn't
                      reached it yet and read as a mismatch, even though the math is correct. */}
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
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">Belum ada snapshot — import file untuk memulai.</CardContent>
          </Card>
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
