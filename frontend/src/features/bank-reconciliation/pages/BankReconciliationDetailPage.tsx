import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeft, Download, FileText, MoreVertical, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { useHasPermission } from '@/shared/hooks/usePermission'
import {
  fetchBankReconciliationComparisonRows,
  fetchBankReconciliationDayDetail,
  fetchBankStatementFileObjectUrl,
  fetchDailyBalancingSummary,
  recomputeReconciliation,
} from '../api/bankReconciliationApi'
import type {
  BankReconciliationComparisonStatus,
  BankReconciliationFile,
  BankReconciliationSummary,
  BankReconciliationStatus,
} from '../types'

const STATUS_LABEL: Record<BankReconciliationStatus, string> = {
  balanced: 'Balanced',
  unbalanced: 'Unbalanced',
  not_uploaded: 'Mutasi bank belum di upload',
}

function StatusPill({ status }: { status: BankReconciliationStatus }) {
  const variant = status === 'balanced' ? 'default' : status === 'unbalanced' ? 'destructive' : 'secondary'
  return <Badge variant={variant}>{STATUS_LABEL[status]}</Badge>
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

const COMPARISON_STATUS_LABEL: Record<BankReconciliationComparisonStatus, string> = {
  match: 'Match',
  not_in_bank: 'Tidak ada di bank',
  not_in_cash_book: 'Tidak ada di cash book',
}

/** Shows whichever side (debit/credit) is actually populated -- a document/line is never both. */
function formatSide(debit: number | null, credit: number | null): string {
  if (debit === null && credit === null) return '-'
  return formatCurrency((debit ?? 0) > 0 ? (debit as number) : (credit ?? 0))
}

/** Point 3: row-per-transaction comparison, Cash Book (system) vs uploaded bank statement, for one day. */
function ComparisonSubTable({ bankAccountId, date }: { bankAccountId: string; date: string }) {
  const comparisonQuery = useQuery({
    queryKey: ['bank-reconciliation-comparison', bankAccountId, date],
    queryFn: () => fetchBankReconciliationComparisonRows(bankAccountId, date),
  })

  if (comparisonQuery.isLoading) {
    return <p className="p-4 text-sm text-muted-foreground">Loading...</p>
  }
  if (comparisonQuery.isError) {
    return <p className="p-4 text-sm text-destructive">Failed to load comparison.</p>
  }
  if (!comparisonQuery.data?.length) {
    return <p className="p-4 text-sm text-muted-foreground">No transactions for this day.</p>
  }

  return (
    <div className="bg-muted/30 p-4">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Tanggal</TableHead>
            <TableHead>Keterangan / No. Voucher (Cash Book)</TableHead>
            <TableHead className="text-right">Debit/Kredit Cash Book</TableHead>
            <TableHead>Keterangan Mutasi Bank</TableHead>
            <TableHead className="text-right">Debit/Kredit Mutasi</TableHead>
            <TableHead className="text-right">Selisih</TableHead>
            <TableHead>Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {comparisonQuery.data.map((row) => (
            <TableRow key={row.id}>
              <TableCell>{formatDate(row.date)}</TableCell>
              <TableCell>{row.cash_book_label ?? '-'}</TableCell>
              <TableCell className="text-right">{formatSide(row.cash_book_debit, row.cash_book_credit)}</TableCell>
              <TableCell>{row.statement_label ?? '-'}</TableCell>
              <TableCell className="text-right">{formatSide(row.statement_debit, row.statement_credit)}</TableCell>
              <TableCell className="text-right">{formatCurrency(row.selisih)}</TableCell>
              <TableCell>
                <Badge variant={row.status === 'match' ? 'default' : 'destructive'}>{COMPARISON_STATUS_LABEL[row.status]}</Badge>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

/** Point 2's "View" -- the day's uploaded file(s) ("folder" contents) plus its Cash Book Transaction rows. */
function DayDetailDialog({ row, onClose }: { row: BankReconciliationSummary; onClose: () => void }) {
  const detailQuery = useQuery({
    queryKey: ['bank-reconciliation-day-detail', row.bank_account_id, row.date],
    queryFn: () => fetchBankReconciliationDayDetail(row.bank_account_id, row.date),
  })

  const handleDownload = async (file: BankReconciliationFile) => {
    const url = await fetchBankStatementFileObjectUrl(file.id)
    const link = document.createElement('a')
    link.href = url
    link.download = file.original_filename
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="w-[95vw] max-h-[85vh] max-w-lg overflow-y-auto rounded-lg p-4 sm:p-6">
        <DialogHeader>
          <DialogTitle className="break-words pr-6">
            {row.bank_account_name} &mdash; {formatDate(row.date)}
          </DialogTitle>
        </DialogHeader>

        <div>
          <h4 className="mb-2 text-sm font-medium">Mutasi Bank (File)</h4>
          {detailQuery.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading...</p>
          ) : !detailQuery.data?.files.length ? (
            <p className="text-sm text-muted-foreground">Belum ada file mutasi.</p>
          ) : (
            <ul className="space-y-2">
              {detailQuery.data.files.map((file) => (
                <li key={file.id} className="flex w-full items-center gap-3 rounded border p-2 text-sm">
                  <FileText className="size-8 shrink-0 text-muted-foreground" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium" title={file.original_filename}>
                      {file.original_filename}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {file.uploaded_by ?? '-'} &middot; {file.uploaded_at ? formatDate(file.uploaded_at) : '-'}
                    </p>
                  </div>
                  <Button size="icon" variant="ghost" className="h-10 w-10 shrink-0" onClick={() => handleDownload(file)}>
                    <Download className="size-4" />
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}

/** Daily balancing table -- one row per bank account (only one exists) + day, in a "Ringkasan" tab.
 * The "⋮" menu's "View" switches to a "Detail" tab showing that row's Cash Book vs bank statement
 * comparison; "See the file" opens that day's uploaded file(s) in a dialog. Row click does nothing.
 */
export function BankReconciliationDetailPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canUpdate = useHasPermission('finance.bank_reconciliation.update')
  const canCreate = useHasPermission('finance.bank_reconciliation.create')

  const [dateFrom, setDateFrom] = useState(() => todayIso())
  const [dateTo, setDateTo] = useState(() => todayIso())
  const [activeTab, setActiveTab] = useState<'summary' | 'detail'>('summary')
  const [detailRow, setDetailRow] = useState<BankReconciliationSummary | null>(null)
  const [viewRow, setViewRow] = useState<BankReconciliationSummary | null>(null)

  const summaryQuery = useQuery({
    queryKey: ['bank-reconciliation-summary', dateFrom, dateTo],
    queryFn: () => fetchDailyBalancingSummary({ date_from: dateFrom, date_to: dateTo }),
  })

  const recomputeMutation = useMutation({
    mutationFn: (row: BankReconciliationSummary) => recomputeReconciliation({ bank_account_id: row.bank_account_id, date_from: row.date, date_to: row.date }),
    onSuccess: () => {
      toast.success('Reconciliation recomputed.')
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-summary'] })
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-comparison'] })
    },
    onError: (error) => toastApiError(error),
  })

  const summaryColumns = useMemo<DataTableColumn<BankReconciliationSummary>[]>(
    () => [
      { header: 'Date', accessor: (row) => formatDate(row.date) },
      {
        header: 'System (Dr/Cr)',
        accessor: (row) => (row.status === 'not_uploaded' ? '-' : `${formatCurrency(row.system_debit_total)} / ${formatCurrency(row.system_credit_total)}`),
      },
      {
        header: 'Statement (Dr/Cr)',
        accessor: (row) => (row.status === 'not_uploaded' ? '-' : `${formatCurrency(row.statement_debit_total)} / ${formatCurrency(row.statement_credit_total)}`),
      },
      {
        header: 'Selisih',
        accessor: (row) => (row.status === 'not_uploaded' ? '-' : `${formatCurrency(row.variance_debit)} / ${formatCurrency(row.variance_credit)}`),
      },
      { header: 'Status', accessor: (row) => <StatusPill status={row.status} /> },
      {
        header: '',
        accessor: (row) => (
          <div className="flex items-center justify-end gap-1">
            {canUpdate && row.status !== 'not_uploaded' && (
              <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); recomputeMutation.mutate(row) }} disabled={recomputeMutation.isPending}>
                <RotateCw className="h-4 w-4" />
              </Button>
            )}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button size="sm" variant="ghost" onClick={(e) => e.stopPropagation()}>
                  <MoreVertical className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
                <DropdownMenuItem onClick={() => { setDetailRow(row); setActiveTab('detail') }}>View</DropdownMenuItem>
                <DropdownMenuItem onClick={() => setViewRow(row)}>See the file</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        ),
      },
    ],
    [canUpdate, recomputeMutation],
  )

  return (
    <div className="space-y-4">
      <PageHeader
        title="Bank Reconciliation"
        description="Daily balancing between uploaded bank statements and Payment Voucher/Official Receipt."
        actions={
          canCreate ? (
            <Button onClick={() => navigate('/finance/bank-reconciliation/upload')}>
              <Upload className="mr-2 h-4 w-4" />
              Upload Statement
            </Button>
          ) : undefined
        }
      />

      <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as 'summary' | 'detail')}>
        <TabsList>
          <TabsTrigger value="summary">Ringkasan</TabsTrigger>
          <TabsTrigger value="detail">Detail</TabsTrigger>
        </TabsList>

        <TabsContent value="summary" className="space-y-4">
          <Card>
            <CardContent className="flex flex-wrap items-end gap-4 pt-6">
              <div className="space-y-1.5">
                <Label>From</Label>
                <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
              </div>
              <div className="space-y-1.5">
                <Label>To</Label>
                <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
              </div>
            </CardContent>
          </Card>

          <DataTable
            columns={summaryColumns}
            data={summaryQuery.data ?? []}
            rowKey={(row) => row.id}
            isLoading={summaryQuery.isLoading}
            isError={summaryQuery.isError}
            onRetry={() => summaryQuery.refetch()}
            emptyMessage="No data for this range."
          />
        </TabsContent>

        <TabsContent value="detail" className="space-y-4">
          {!detailRow ? (
            <p className="p-4 text-sm text-muted-foreground">Pilih data lewat menu &#8942; &rarr; View.</p>
          ) : (
            <>
              <div className="flex items-center gap-3">
                <Button size="sm" variant="outline" onClick={() => setActiveTab('summary')}>
                  <ArrowLeft className="mr-2 h-4 w-4" />
                  Kembali ke Ringkasan
                </Button>
                <span className="text-sm font-medium">
                  {detailRow.bank_account_name} &mdash; {formatDate(detailRow.date)}
                </span>
              </div>
              <ComparisonSubTable bankAccountId={detailRow.bank_account_id} date={detailRow.date} />
            </>
          )}
        </TabsContent>
      </Tabs>

      {viewRow && <DayDetailDialog row={viewRow} onClose={() => setViewRow(null)} />}
    </div>
  )
}
