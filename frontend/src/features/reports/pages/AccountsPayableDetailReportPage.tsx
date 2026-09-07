import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Download, Printer, RotateCw, Wallet, CalendarClock, TrendingDown, Landmark } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader as TableHeaderRow, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { exportAccountsPayables, fetchAccountsPayables, fetchAccountsPayableSummary } from '@/features/payment/api/accountsPayableApi'
import { fetchAccountsPayableGroupedDetail } from '../api/accountsPayableGroupedDetailApi'
import { fetchUnallocatedPaymentVouchers } from '../api/accountsPayableUnallocatedApi'
import type { AccountsPayable } from '@/features/payment/types'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { AccountsPayableDetailReportFiltersBar } from '../components/AccountsPayableDetailReportFiltersBar'
import { emptyApDetailReportFilters } from '../lib/reportFilters'
import type { ApDetailReportFilterValues } from '../types'

type ViewMode = 'aging' | 'grouped'

const today = () => new Date().toISOString().slice(0, 10)

/** AP mirror of AccountsReceivableDetailReportPage — same structure/components/style, retargeted at Supplier/Purchase Invoice. No row-selection column (AR's exists only for its own Tanda Terima Invoice print flow, which AP has no equivalent of). */
export function AccountsPayableDetailReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ApDetailReportFilterValues>(emptyApDetailReportFilters)
  const [viewMode, setViewMode] = useState<ViewMode>('aging')

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
    placeholderData: (previous) => previous,
  })

  const allRows = listQuery.data?.data ?? []
  // No server-side search on this endpoint — narrow the currently-loaded page client-side, same ceiling as every other report's Search box (identical rule to AR Detail).
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
    enabled: viewMode === 'grouped',
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

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="AP Detail"
        description="Every outstanding and settled payable, by supplier and invoice."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} payables` : undefined}
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
                  onClick: () => navigate(`/reports/ap-detail/print${printParams ? `?${printParams}` : ''}`),
                },
              ]}
            />
          </>
        }
      />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard
          title="Total Hutang"
          value={formatCurrency(summaryQuery.data?.total_outstanding ?? 0)}
          icon={Landmark}
          isLoading={summaryQuery.isLoading}
        />
        <SummaryCard
          title="Jatuh Tempo Minggu Ini"
          value={formatCurrency(summaryQuery.data?.due_this_week ?? 0)}
          icon={CalendarClock}
          tone="warning"
          isLoading={summaryQuery.isLoading}
        />
        <SummaryCard
          title="Sudah Lewat Jatuh Tempo"
          value={formatCurrency(summaryQuery.data?.overdue ?? 0)}
          icon={TrendingDown}
          tone="danger"
          isLoading={summaryQuery.isLoading}
        />
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
        </div>
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
    </div>
  )
}
