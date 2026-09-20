import { Fragment, useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ChevronDown, ChevronRight, Download, Printer, RotateCw, Upload, Wallet, CalendarClock, TrendingDown, Landmark } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { Alert, AlertTitle } from '@/components/ui/alert'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader as TableHeaderRow, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { exportAccountsPayables, fetchAccountsPayables, fetchAccountsPayableSummary } from '@/features/payment/api/accountsPayableApi'
import { fetchAccountsPayableGroupedDetail } from '../api/accountsPayableGroupedDetailApi'
import { fetchUnallocatedPaymentVouchers } from '../api/accountsPayableUnallocatedApi'
import type { AccountsPayable } from '@/features/payment/types'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { openPrintWindow } from '@/shared/lib/printOptions'
import { toastApiError } from '@/shared/services/errorHandler'
import { AccountsPayableDetailReportFiltersBar } from '../components/AccountsPayableDetailReportFiltersBar'
import { SupplierOutstandingArchiveFiltersBar } from '../components/SupplierOutstandingArchiveFiltersBar'
import { SupplierOutstandingArchiveImportDialog } from '../components/SupplierOutstandingArchiveImportDialog'
import {
  exportSupplierOutstandingArchive,
  fetchSupplierOutstandingArchiveDetail,
  fetchSupplierOutstandingSnapshots,
} from '../api/supplierOutstandingArchiveApi'
import { emptyApDetailReportFilters, emptySupplierOutstandingArchiveFilters } from '../lib/reportFilters'
import { AGING_BUCKETS, AGING_BUCKET_LABELS, bucketForOverdueDays } from '../lib/outstandingArchiveAging'
import type { ApDetailReportFilterValues, SupplierOutstandingArchiveFilterValues } from '../types'

type ViewMode = 'aging' | 'grouped' | 'archive'

const today = () => new Date().toISOString().slice(0, 10)

/**
 * AP mirror of AccountsReceivableDetailReportPage — same structure/components/style, retargeted
 * at Supplier/Purchase Invoice. Falls back to the imported Supplier Outstanding Bills snapshot
 * for Aging List / Perincian Hutang whenever the live accounts_payables table is completely
 * empty (never mixed with live data in one table) — see
 * SupplierOutstandingArchiveImportService's own docblock. The "Uang Muka / Belum Teralokasi"
 * section below is unrelated to AP invoices (it's PaymentVoucher data) and always stays live,
 * snapshot mode or not.
 */
export function AccountsPayableDetailReportPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ApDetailReportFilterValues>(emptyApDetailReportFilters)
  const [viewMode, setViewMode] = useState<ViewMode>('aging')

  // Unfiltered, fired once — independent of whatever the active table filters narrow down to.
  const liveDataCheckQuery = useQuery({
    queryKey: ['ap-detail-has-live-data'],
    queryFn: () => fetchAccountsPayables({ page: 1, per_page: 1 }),
    staleTime: 60_000,
  })
  const liveDataChecked = !liveDataCheckQuery.isLoading
  const hasLiveData = (liveDataCheckQuery.data?.meta.total ?? 0) > 0
  const snapshotMode = liveDataChecked && !hasLiveData

  const activeFilterParams = {
    ...(filters.supplier_id ? { supplier_id: filters.supplier_id } : {}),
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.agingBucket ? { aging_bucket: filters.agingBucket } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
    ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
  }

  const printParams = new URLSearchParams(activeFilterParams).toString()

  const listQuery = useQuery({
    queryKey: ['ap-detail-report', page, activeFilterParams],
    queryFn: () => fetchAccountsPayables({ page, ...activeFilterParams }),
    enabled: viewMode === 'aging' && hasLiveData,
    placeholderData: (previous) => previous,
  })

  const allRows = listQuery.data?.data ?? []
  const rows = search
    ? allRows.filter(
        (row) =>
          row.supplier?.supplier_name.toLowerCase().includes(search.toLowerCase()) ||
          row.invoice?.document_number?.toLowerCase().includes(search.toLowerCase()),
      )
    : allRows

  const hasFilters = !!(search || Object.keys(activeFilterParams).length > 0)

  const summaryQuery = useQuery({ queryKey: ['ap-detail-summary'], queryFn: fetchAccountsPayableSummary })
  const unallocatedQuery = useQuery({ queryKey: ['ap-detail-unallocated'], queryFn: fetchUnallocatedPaymentVouchers })

  const groupedQuery = useQuery({
    queryKey: ['ap-detail-grouped', activeFilterParams],
    queryFn: () => fetchAccountsPayableGroupedDetail(activeFilterParams),
    enabled: viewMode === 'grouped' && hasLiveData,
    placeholderData: (previous) => previous,
  })

  const [isExporting, setIsExporting] = useState(false)
  const exportReport = async (type: 'detail' | 'summary', format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportAccountsPayables(activeFilterParams, type, format)
      downloadBlob(`Supplier${type === 'detail' ? 'Detail' : 'Summary'}Aging.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const isOverdue = (row: AccountsPayable) => row.status !== 'paid' && row.due_date < today()

  const columns: DataTableColumn<AccountsPayable>[] = [
    { header: 'Supplier', accessor: (row) => row.supplier?.supplier_name ?? '—' },
    { header: 'Warehouse', accessor: (row) => row.warehouse_name ?? '—' },
    { header: 'Invoice Number', accessor: (row) => row.invoice?.document_number ?? '—' },
    { header: 'Invoice Date', accessor: (row) => (row.invoice?.invoice_date ? formatDate(row.invoice.invoice_date) : '—') },
    { header: 'Umur', accessor: (row) => (row.age_in_days !== null ? `${row.age_in_days} hari` : '—') },
    { header: 'Due Date', accessor: (row) => formatDate(row.due_date) },
    { header: 'Total Invoice', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
    { header: 'Paid Amount', accessor: (row) => formatCurrency(row.paid_amount), className: 'text-right' },
    { header: 'Outstanding Amount', accessor: (row) => formatCurrency(row.outstanding_amount), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  ]

  // Supplier Outstanding Bills archive — standalone, see SupplierOutstandingArchiveImportService.
  const canViewArchive = useHasPermission('reports.ap_archive.view')
  const canImportArchive = useHasPermission('reports.ap_archive.import')
  const [archiveImportOpen, setArchiveImportOpen] = useState(false)
  const [archiveHistoryOpen, setArchiveHistoryOpen] = useState(false)
  const [selectedSnapshotId, setSelectedSnapshotId] = useState<string | null>(null)
  const [archiveFilters, setArchiveFilters] = useState<SupplierOutstandingArchiveFilterValues>(emptySupplierOutstandingArchiveFilters)

  const snapshotsQuery = useQuery({
    queryKey: ['supplier-outstanding-snapshots'],
    queryFn: fetchSupplierOutstandingSnapshots,
    enabled: canViewArchive,
  })

  useEffect(() => {
    if (selectedSnapshotId === null && snapshotsQuery.data && snapshotsQuery.data.length > 0) {
      setSelectedSnapshotId(snapshotsQuery.data[0].id)
    }
  }, [snapshotsQuery.data, selectedSnapshotId])

  const archiveDetailQuery = useQuery({
    queryKey: ['supplier-outstanding-archive-detail', selectedSnapshotId, archiveFilters],
    queryFn: () => fetchSupplierOutstandingArchiveDetail(selectedSnapshotId as string, archiveFilters),
    enabled: !!selectedSnapshotId && (viewMode === 'archive' || snapshotMode),
    placeholderData: (previous) => previous,
  })

  const selectedSnapshot = snapshotsQuery.data?.find((s) => s.id === selectedSnapshotId) ?? archiveDetailQuery.data?.snapshot

  const exportArchive = async (format: 'xlsx' | 'csv') => {
    if (!selectedSnapshotId) return
    setIsExporting(true)
    try {
      const blob = await exportSupplierOutstandingArchive(selectedSnapshotId, archiveFilters, format)
      downloadBlob(`HutangSupplierArsip.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  // Flat Aging List rows (not bucketed — matches this page's own existing Aging List shape,
  // unlike AR's, which buckets inline). Warehouse has no equivalent in the snapshot at all.
  const agingRows = useMemo(
    () =>
      (archiveDetailQuery.data?.suppliers ?? []).flatMap((supplier) =>
        supplier.rows.map((row) => ({ ...row, supplier_code: supplier.supplier_code, supplier_name: supplier.supplier_name })),
      ),
    [archiveDetailQuery.data],
  )

  // Supplier x aging-bucket matrix — mirrors this page's existing "Perincian Hutang" shape exactly.
  const agingMatrix = useMemo(() => {
    if (!archiveDetailQuery.data) return { rows: [], total: { not_due: 0, d1_30: 0, d31_60: 0, d61_90: 0, over_90: 0, total: 0 } }

    const rows = archiveDetailQuery.data.suppliers.map((supplier) => {
      const buckets = { not_due: 0, d1_30: 0, d31_60: 0, d61_90: 0, over_90: 0 }
      supplier.rows.forEach((row) => {
        buckets[bucketForOverdueDays(row.overdue_days)] += row.unpaid_amount
      })
      const total = AGING_BUCKETS.reduce((sum, key) => sum + buckets[key], 0)
      return { supplier_code: supplier.supplier_code, supplier_name: supplier.supplier_name, ...buckets, total }
    })

    const total = {
      not_due: rows.reduce((s, r) => s + r.not_due, 0),
      d1_30: rows.reduce((s, r) => s + r.d1_30, 0),
      d31_60: rows.reduce((s, r) => s + r.d31_60, 0),
      d61_90: rows.reduce((s, r) => s + r.d61_90, 0),
      over_90: rows.reduce((s, r) => s + r.over_90, 0),
      total: rows.reduce((s, r) => s + r.total, 0),
    }

    return { rows, total }
  }, [archiveDetailQuery.data])

  const snapshotBanner = snapshotMode && selectedSnapshot && (
    <Alert>
      <AlertTitle>Sumber: import manual per {formatDate(selectedSnapshot.snapshot_as_of_date)} — bukan data live.</AlertTitle>
    </Alert>
  )

  const noSnapshotEmptyState = (
    <Card>
      <CardContent className="flex flex-col items-center gap-3 py-10 text-center text-muted-foreground">
        <p>Belum ada data hutang. Import file Supplier Outstanding Bills dari Skybiz melalui tombol Import di kanan atas.</p>
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
        title="AP Detail"
        description={
          snapshotMode && selectedSnapshot
            ? `Hutang yang belum lunas per ${formatDate(selectedSnapshot.snapshot_as_of_date)}.`
            : 'Every outstanding and settled payable, by supplier and invoice.'
        }
        count={
          viewMode === 'archive' || snapshotMode
            ? archiveDetailQuery.data
              ? `${formatNumber(archiveDetailQuery.data.suppliers.length)} supplier`
              : undefined
            : listQuery.data?.meta
              ? `${formatNumber(listQuery.data.meta.total)} payables`
              : undefined
        }
        actions={
          viewMode === 'archive' || snapshotMode ? (
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
                    onClick: () => openPrintWindow(`/reports/ap-detail/print${printParams ? `?${printParams}` : ''}`),
                  },
                ]}
              />
            </>
          )
        }
      />

      {snapshotBanner}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard
          title="Total Hutang"
          // In snapshot mode this deliberately reads the same FILTERED grand_total_unpaid Aging
          // List/Perincian Hutang/Supplier Outstanding Bills all show, so the two can never disagree.
          value={formatCurrency(snapshotMode ? (archiveDetailQuery.data?.grand_total_unpaid ?? 0) : (summaryQuery.data?.total_outstanding ?? 0))}
          icon={Landmark}
          isLoading={snapshotMode ? archiveDetailQuery.isLoading : summaryQuery.isLoading}
        />
        <SummaryCard
          title="Jatuh Tempo Minggu Ini"
          value={formatCurrency(snapshotMode ? (archiveDetailQuery.data?.summary.due_this_week ?? 0) : (summaryQuery.data?.due_this_week ?? 0))}
          icon={CalendarClock}
          tone="warning"
          isLoading={snapshotMode ? archiveDetailQuery.isLoading : summaryQuery.isLoading}
        />
        <SummaryCard
          title="Sudah Lewat Jatuh Tempo"
          value={formatCurrency(snapshotMode ? (archiveDetailQuery.data?.summary.overdue ?? 0) : (summaryQuery.data?.overdue ?? 0))}
          icon={TrendingDown}
          tone="danger"
          isLoading={snapshotMode ? archiveDetailQuery.isLoading : summaryQuery.isLoading}
        />
        {/* Unrelated to AP invoices (PaymentVoucher data, not derivable from the snapshot at all) -- always live, snapshot mode or not. */}
        <SummaryCard
          title="Uang Muka Belum Teralokasi"
          value={formatCurrency(summaryQuery.data?.unallocated_total ?? 0)}
          icon={Wallet}
          tone="warning"
          isLoading={summaryQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button size="sm" variant={viewMode === 'aging' ? 'default' : 'ghost'} onClick={() => setViewMode('aging')}>
            Aging List
          </Button>
          <Button size="sm" variant={viewMode === 'grouped' ? 'default' : 'ghost'} onClick={() => setViewMode('grouped')}>
            Perincian Hutang
          </Button>
          {canViewArchive && (
            <Button size="sm" variant={viewMode === 'archive' ? 'default' : 'ghost'} onClick={() => setViewMode('archive')}>
              Supplier Outstanding Bills
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
            <SupplierOutstandingArchiveFiltersBar value={archiveFilters} onChange={setArchiveFilters} />
          </>
        ) : (
          <>
            <SearchBox
              value={search}
              onChange={(value) => {
                setSearch(value)
                setPage(1)
              }}
              placeholder="Search invoice number or supplier…"
            />
            <AccountsPayableDetailReportFiltersBar
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
                  <TableHeaderRow>
                    <TableRow>
                      <TableHead>Supplier</TableHead>
                      <TableHead>Warehouse</TableHead>
                      <TableHead>Invoice Number</TableHead>
                      <TableHead>Invoice Date</TableHead>
                      <TableHead>Umur</TableHead>
                      <TableHead>Due Date</TableHead>
                      <TableHead className="text-right">Total Invoice</TableHead>
                      <TableHead className="text-right">Paid Amount</TableHead>
                      <TableHead className="text-right">Outstanding Amount</TableHead>
                      <TableHead>Status</TableHead>
                    </TableRow>
                  </TableHeaderRow>
                  <TableBody>
                    {agingRows.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={10} className="py-8 text-center text-muted-foreground">
                          {archiveDetailQuery.isLoading ? 'Loading…' : 'Tidak ada data yang cocok dengan filter.'}
                        </TableCell>
                      </TableRow>
                    )}
                    {agingRows.map((row) => (
                      <TableRow key={row.id} className={row.overdue_days > 0 ? 'text-destructive' : undefined}>
                        <TableCell>{row.supplier_name}</TableCell>
                        <TableCell>—</TableCell>
                        <TableCell>{row.ref_no}</TableCell>
                        <TableCell>{formatDate(row.txn_date)}</TableCell>
                        <TableCell>{row.overdue_days > 0 ? `${row.overdue_days} hari` : '—'}</TableCell>
                        <TableCell>{formatDate(row.due_date)}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.invoice_amount)}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.paid_amount)}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.unpaid_amount)}</TableCell>
                        <TableCell><StatusBadge status={row.status} /></TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {agingRows.length > 0 && (
                <Card>
                  <CardContent className="flex items-center justify-end gap-2 py-4 text-base">
                    <span className="text-muted-foreground">Total Outstanding</span>
                    <span className="font-semibold">{formatCurrency(archiveDetailQuery.data?.grand_total_unpaid ?? 0)}</span>
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
              emptyMessage={hasFilters ? 'No payables match your search or filters.' : 'No payables yet.'}
              rowClassName={(row) => (isOverdue(row) ? 'text-destructive' : undefined)}
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
      ) : viewMode === 'archive' ? (
        !selectedSnapshotId ? (
          noSnapshotEmptyState
        ) : !archiveDetailQuery.data || archiveDetailQuery.data.suppliers.length === 0 ? (
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
                  {archiveDetailQuery.data.suppliers.map((supplier) => (
                    <Fragment key={supplier.supplier_code}>
                      <TableRow className="bg-muted/20">
                        <TableCell colSpan={8} className="font-medium">
                          {supplier.supplier_code} — {supplier.supplier_name}
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
                      {supplier.rows.map((row) => (
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
                          Subtotal — {supplier.supplier_name}
                        </TableCell>
                        <TableCell className="text-right font-medium">{formatCurrency(supplier.subtotal_unpaid)}</TableCell>
                        <TableCell colSpan={2} />
                        <TableCell className="text-right font-medium">{formatCurrency(supplier.subtotal_overdue)}</TableCell>
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
      ) : // viewMode === 'grouped' (Perincian Hutang)
      snapshotMode ? (
        !selectedSnapshotId ? (
          noSnapshotEmptyState
        ) : agingMatrix.rows.length === 0 ? (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">
              {archiveDetailQuery.isLoading ? 'Loading…' : 'Tidak ada data yang cocok dengan filter.'}
            </CardContent>
          </Card>
        ) : (
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableHeaderRow>
                <TableRow>
                  <TableHead>Supplier</TableHead>
                  {AGING_BUCKETS.map((key) => (
                    <TableHead key={key} className="text-right">{AGING_BUCKET_LABELS[key]}</TableHead>
                  ))}
                  <TableHead className="text-right">Total Hutang</TableHead>
                </TableRow>
              </TableHeaderRow>
              <TableBody>
                {agingMatrix.rows.map((row) => (
                  <TableRow key={row.supplier_code}>
                    <TableCell className="font-medium">{row.supplier_name}</TableCell>
                    <TableCell className="text-right">{formatCurrency(row.not_due)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(row.d1_30)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(row.d31_60)}</TableCell>
                    <TableCell className="text-right text-destructive">{formatCurrency(row.d61_90)}</TableCell>
                    <TableCell className="text-right text-destructive">{formatCurrency(row.over_90)}</TableCell>
                    <TableCell className="text-right font-semibold">{formatCurrency(row.total)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
              <TableFooter>
                <TableRow>
                  <TableCell className="font-semibold">TOTAL</TableCell>
                  <TableCell className="text-right font-semibold">{formatCurrency(agingMatrix.total.not_due)}</TableCell>
                  <TableCell className="text-right font-semibold">{formatCurrency(agingMatrix.total.d1_30)}</TableCell>
                  <TableCell className="text-right font-semibold">{formatCurrency(agingMatrix.total.d31_60)}</TableCell>
                  <TableCell className="text-right font-semibold">{formatCurrency(agingMatrix.total.d61_90)}</TableCell>
                  <TableCell className="text-right font-semibold">{formatCurrency(agingMatrix.total.over_90)}</TableCell>
                  <TableCell className="text-right font-semibold">{formatCurrency(agingMatrix.total.total)}</TableCell>
                </TableRow>
              </TableFooter>
            </Table>
          </div>
        )
      ) : !groupedQuery.data || groupedQuery.data.rows.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            {groupedQuery.isLoading ? 'Loading…' : 'No payables match your filters.'}
          </CardContent>
        </Card>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeaderRow>
              <TableRow>
                <TableHead>Supplier</TableHead>
                <TableHead className="text-right">Belum Jatuh Tempo</TableHead>
                <TableHead className="text-right">1-30 Hari</TableHead>
                <TableHead className="text-right">31-60 Hari</TableHead>
                <TableHead className="text-right">61-90 Hari</TableHead>
                <TableHead className="text-right">&gt; 90 Hari</TableHead>
                <TableHead className="text-right">Total Hutang</TableHead>
              </TableRow>
            </TableHeaderRow>
            <TableBody>
              {groupedQuery.data.rows.map((row) => (
                <TableRow key={row.supplier_id}>
                  <TableCell className="font-medium">{row.supplier_name}</TableCell>
                  <TableCell className="text-right">{formatCurrency(row.not_due)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(row.due_1_30)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(row.due_31_60)}</TableCell>
                  <TableCell className="text-right text-destructive">{formatCurrency(row.due_61_90)}</TableCell>
                  <TableCell className="text-right text-destructive">{formatCurrency(row.due_over_90)}</TableCell>
                  <TableCell className="text-right font-semibold">{formatCurrency(row.total)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell className="font-semibold">TOTAL</TableCell>
                <TableCell className="text-right font-semibold">{formatCurrency(groupedQuery.data.total.not_due)}</TableCell>
                <TableCell className="text-right font-semibold">{formatCurrency(groupedQuery.data.total.due_1_30)}</TableCell>
                <TableCell className="text-right font-semibold">{formatCurrency(groupedQuery.data.total.due_31_60)}</TableCell>
                <TableCell className="text-right font-semibold">{formatCurrency(groupedQuery.data.total.due_61_90)}</TableCell>
                <TableCell className="text-right font-semibold">{formatCurrency(groupedQuery.data.total.due_over_90)}</TableCell>
                <TableCell className="text-right font-semibold">{formatCurrency(groupedQuery.data.total.total)}</TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </div>
      )}

      <Card className="border-amber-500/50">
        <CardHeader className="flex flex-row items-center gap-2">
          <AlertTriangle className="size-4 text-amber-500" />
          <CardTitle className="text-base">Uang Muka / Belum Teralokasi</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="mb-3 text-sm text-muted-foreground">
            Setiap baris di sini adalah pembayaran ke supplier yang belum dialokasikan ke invoice manapun — laporan hutang
            belum akurat sampai dialokasikan.
          </p>
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableHeaderRow>
                <TableRow>
                  <TableHead>Tanggal</TableHead>
                  <TableHead>No PV</TableHead>
                  <TableHead>Supplier</TableHead>
                  <TableHead className="text-right">Nilai</TableHead>
                  <TableHead>Metode Bayar</TableHead>
                </TableRow>
              </TableHeaderRow>
              <TableBody>
                {unallocatedQuery.isLoading ? (
                  <TableRow>
                    <TableCell colSpan={5} className="text-center text-muted-foreground">
                      Loading…
                    </TableCell>
                  </TableRow>
                ) : !unallocatedQuery.data || unallocatedQuery.data.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5} className="text-center text-muted-foreground">
                      No unallocated payment vouchers.
                    </TableCell>
                  </TableRow>
                ) : (
                  unallocatedQuery.data.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>{row.payment_date ? formatDate(row.payment_date) : '—'}</TableCell>
                      <TableCell>{row.document_number ?? '—'}</TableCell>
                      <TableCell>{row.supplier_name ?? '—'}</TableCell>
                      <TableCell className="text-right">{formatCurrency(row.unallocated_amount)}</TableCell>
                      <TableCell>{row.payment_method ?? '—'}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      <SupplierOutstandingArchiveImportDialog
        open={archiveImportOpen}
        onClose={() => setArchiveImportOpen(false)}
        onImported={(snapshot) => setSelectedSnapshotId(snapshot.id)}
      />
    </div>
  )
}
