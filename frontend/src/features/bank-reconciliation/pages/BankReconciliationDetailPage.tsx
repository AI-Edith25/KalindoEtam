import { useEffect, useMemo, useState } from 'react'
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { ConfirmationDialog } from '@/components/shared/ConfirmationDialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { useHasPermission } from '@/shared/hooks/usePermission'
import {
  deleteBankReconciliationForDate,
  fetchBankReconciliationComparisonRows,
  fetchBankReconciliationDayDetail,
  fetchBankReconciliationMatching,
  fetchBankStatementFileObjectUrl,
  fetchDailyBalancingSummary,
  recomputeReconciliation,
} from '../api/bankReconciliationApi'
import type {
  BankReconciliationCategoryComparison,
  BankReconciliationFile,
  BankReconciliationMatchStatus,
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

function formatSaldo(value: number | null): string {
  return value === null ? '-' : formatCurrency(value)
}

/** One category (Debit/Kredit) of the aggregate Cash Book vs Mutasi Bank comparison. */
function CategoryComparisonRow({ label, comparison }: { label: string; comparison: BankReconciliationCategoryComparison }) {
  return (
    <div className="flex items-center justify-between gap-4 rounded border p-3 text-sm">
      <div>
        <p className="font-medium">{label}</p>
        <p className="text-muted-foreground">
          Cash Book {formatCurrency(comparison.cash_book)} vs Mutasi Bank {formatCurrency(comparison.bank)}
          {comparison.status === 'unbalanced' && <> &middot; Selisih {formatCurrency(comparison.variance)}</>}
        </p>
      </div>
      <StatusPill status={comparison.status} />
    </div>
  )
}

function MatchStatusBadge({ status, selisih }: { status: BankReconciliationMatchStatus; selisih: number }) {
  const isCocok = status === 'cocok'
  const className = isCocok
    ? 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300'
    : 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300'
  const label = isCocok ? 'Cocok' : 'Tidak Cocok'

  return (
    <Badge className={className}>
      {label}
      {isCocok && selisih !== 0 && <> &middot; selisih {formatCurrency(Math.abs(selisih))}</>}
    </Badge>
  )
}

/**
 * Detail tab's row-level "Tabel Perbandingan" -- Cash Book (JL) vs mutasi bank, matched 1:1 by
 * nominal only (see BankStatementMatcher on the backend), scoped to one account+date. Tidak Cocok
 * rows sort first (backend order) so what needs checking is immediately visible.
 */
function MatchingComparisonTable({ date, bankAccountId }: { date: string; bankAccountId: string }) {
  const matchingQuery = useQuery({
    queryKey: ['bank-reconciliation-matching', date, bankAccountId],
    queryFn: () => fetchBankReconciliationMatching(date, bankAccountId),
  })

  if (matchingQuery.isLoading) {
    return <p className="p-4 text-sm text-muted-foreground">Loading...</p>
  }
  if (matchingQuery.isError) {
    return <p className="p-4 text-sm text-destructive">Failed to load the comparison table.</p>
  }

  const data = matchingQuery.data
  if (!data) return null

  return (
    <div className="space-y-2 rounded border bg-background p-3">
      <p className="text-sm font-medium">Tabel Perbandingan</p>
      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead colSpan={3} className="border-r text-center">Report System</TableHead>
              <TableHead colSpan={2} className="border-r text-center">Mutasi Bank</TableHead>
              <TableHead className="text-center">Status</TableHead>
            </TableRow>
            <TableRow>
              <TableHead>Transaction</TableHead>
              <TableHead>Reference</TableHead>
              <TableHead className="border-r text-right">Debit / Credit</TableHead>
              <TableHead>Keterangan</TableHead>
              <TableHead className="border-r text-right">Debit / Credit</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {data.rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-sm text-muted-foreground">
                  No data for this account/day.
                </TableCell>
              </TableRow>
            ) : (
              data.rows.map((row, index) => (
                <TableRow key={index}>
                  <TableCell>{row.jl?.transaction ?? '-'}</TableCell>
                  <TableCell>{row.jl?.reference ?? '-'}</TableCell>
                  <TableCell className="border-r text-right">
                    {row.jl ? formatCurrency(row.jl.debit > 0 ? row.jl.debit : row.jl.kredit) : '-'}
                  </TableCell>
                  <TableCell className="max-w-[16rem] truncate">{row.mutasi?.keterangan ?? '-'}</TableCell>
                  <TableCell className="border-r text-right">
                    {row.mutasi ? formatCurrency(row.mutasi.debit > 0 ? row.mutasi.debit : row.mutasi.kredit) : '-'}
                  </TableCell>
                  <TableCell className="text-center">
                    <MatchStatusBadge status={row.status} selisih={row.selisih} />
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
      <div className="grid gap-1 pt-1 text-sm text-muted-foreground sm:grid-cols-2">
        <span>Cocok: {data.totals.matched_count}</span>
        <span>
          Tidak Cocok: {data.totals.unmatched_jl_count + data.totals.unmatched_mutasi_count} (JL tanpa mutasi:{' '}
          {data.totals.unmatched_jl_count}, mutasi tanpa JL: {data.totals.unmatched_mutasi_count})
        </span>
        <span>Total Tidak Cocok Debit: {formatCurrency(data.totals.unmatched_debit_total)}</span>
        <span>Total Tidak Cocok Credit: {formatCurrency(data.totals.unmatched_credit_total)}</span>
      </div>
    </div>
  )
}

/**
 * Detail tab: Cash Book (Official Receipt/Payment Voucher, read straight from those documents'
 * own fields) for one day, compared against that day's uploaded bank statement at the aggregate
 * level only -- never row-by-row, since a transfer's sender name never matches the
 * customer/supplier name in the system. The Tabel Perbandingan below IS row-level, but matches on
 * nominal only, never name -- and needs one account picked since a day can have more than one.
 */
function ComparisonSubTable({ date }: { date: string }) {
  const comparisonQuery = useQuery({
    queryKey: ['bank-reconciliation-comparison', date],
    queryFn: () => fetchBankReconciliationComparisonRows(date),
  })
  const [selectedAccountId, setSelectedAccountId] = useState<string>('')

  const bankAccounts = comparisonQuery.data?.bank_accounts ?? []
  useEffect(() => {
    if (bankAccounts.length > 0 && !bankAccounts.some((account) => account.id === selectedAccountId)) {
      setSelectedAccountId(bankAccounts[0].id)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [bankAccounts])

  if (comparisonQuery.isLoading) {
    return <p className="p-4 text-sm text-muted-foreground">Loading...</p>
  }
  if (comparisonQuery.isError) {
    return <p className="p-4 text-sm text-destructive">Failed to load comparison.</p>
  }

  const data = comparisonQuery.data
  if (!data) return null

  return (
    <div className="space-y-4 bg-muted/30 p-4">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Transaction</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Reference</TableHead>
            <TableHead>Bank Account</TableHead>
            <TableHead className="text-right">Debit</TableHead>
            <TableHead className="text-right">Credit</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {data.rows.length === 0 ? (
            <TableRow>
              <TableCell colSpan={6} className="text-center text-sm text-muted-foreground">
                No transactions for this day.
              </TableCell>
            </TableRow>
          ) : (
            data.rows.map((row, index) => (
              <TableRow key={row.document_number ?? index}>
                <TableCell>{row.document_number ?? '-'}</TableCell>
                <TableCell>{formatDate(row.date)}</TableCell>
                <TableCell>{row.reference_number ?? '-'}</TableCell>
                <TableCell>{row.bank_account ?? '-'}</TableCell>
                <TableCell className="text-right">{row.debit > 0 ? formatCurrency(row.debit) : '-'}</TableCell>
                <TableCell className="text-right">{row.kredit > 0 ? formatCurrency(row.kredit) : '-'}</TableCell>
              </TableRow>
            ))
          )}
        </TableBody>
        <tfoot>
          <TableRow className="font-medium">
            <TableCell colSpan={4}>Total</TableCell>
            <TableCell className="text-right">{formatCurrency(data.totals.debit)}</TableCell>
            <TableCell className="text-right">{formatCurrency(data.totals.kredit)}</TableCell>
          </TableRow>
        </tfoot>
      </Table>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2 rounded border bg-background p-3 text-sm">
          <p className="font-medium">Ringkasan Mutasi Bank</p>
          <div className="grid grid-cols-2 gap-1 text-muted-foreground">
            <span>Saldo awal</span>
            <span className="text-right">{formatSaldo(data.bank_mutasi.saldo_awal)}</span>
            <span>Total masuk (kredit)</span>
            <span className="text-right">{formatCurrency(data.bank_mutasi.total_masuk)}</span>
            <span>Total keluar (debit)</span>
            <span className="text-right">{formatCurrency(data.bank_mutasi.total_keluar)}</span>
            <span>Saldo akhir</span>
            <span className="text-right">{formatSaldo(data.bank_mutasi.saldo_akhir)}</span>
          </div>
        </div>

        <div className="space-y-2">
          <CategoryComparisonRow label="Debit (Keluar)" comparison={data.comparison.debit} />
          <CategoryComparisonRow label="Kredit (Masuk)" comparison={data.comparison.kredit} />
        </div>
      </div>

      {bankAccounts.length > 1 && (
        <div className="max-w-xs space-y-1.5">
          <Label>Bank Account</Label>
          <Select value={selectedAccountId} onValueChange={setSelectedAccountId}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {bankAccounts.map((account) => (
                <SelectItem key={account.id} value={account.id}>
                  {account.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {selectedAccountId && <MatchingComparisonTable date={date} bankAccountId={selectedAccountId} />}
    </div>
  )
}

/** Point 2's "View" -- the day's uploaded file(s) ("folder" contents) plus its Cash Book Transaction rows. */
function DayDetailDialog({ row, onClose }: { row: BankReconciliationSummary; onClose: () => void }) {
  const detailQuery = useQuery({
    queryKey: ['bank-reconciliation-day-detail', row.date],
    queryFn: () => fetchBankReconciliationDayDetail(row.date),
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
          <DialogTitle className="break-words pr-6">{formatDate(row.date)}</DialogTitle>
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

/** Daily balancing row for one day (every cash/bank account's Payment Voucher/Official Receipt
 * activity vs that day's uploaded statement, all in one bucket -- see BankReconciliationService's
 * own docblock for why there's no per-account split), in a "Ringkasan" tab. The "⋮" menu's "View"
 * switches to a "Detail" tab showing that row's Cash Book vs bank statement comparison; "See the
 * file" opens that day's uploaded file(s) in a dialog. Row click does nothing.
 */
export function BankReconciliationDetailPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canUpdate = useHasPermission('finance.bank_reconciliation.update')
  const canCreate = useHasPermission('finance.bank_reconciliation.create')
  const canDelete = useHasPermission('finance.bank_reconciliation.delete')

  const [date, setDate] = useState(() => todayIso())
  const [activeTab, setActiveTab] = useState<'summary' | 'detail'>('summary')
  const [detailRow, setDetailRow] = useState<BankReconciliationSummary | null>(null)
  const [viewRow, setViewRow] = useState<BankReconciliationSummary | null>(null)
  const [deletingRow, setDeletingRow] = useState<BankReconciliationSummary | null>(null)

  const summaryQuery = useQuery({
    queryKey: ['bank-reconciliation-summary', date],
    queryFn: () => fetchDailyBalancingSummary({ date_from: date, date_to: date }),
  })
  const dayRow = summaryQuery.data?.[0] ?? null

  const recomputeMutation = useMutation({
    mutationFn: (row: BankReconciliationSummary) => recomputeReconciliation({ date_from: row.date, date_to: row.date }),
    onSuccess: () => {
      toast.success('Reconciliation recomputed.')
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-summary'] })
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-comparison'] })
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: (row: BankReconciliationSummary) => deleteBankReconciliationForDate(row.date),
    onSuccess: () => {
      toast.success('Mutasi bank deleted.')
      setDeletingRow(null)
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-summary'] })
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
                {canDelete && (
                  <DropdownMenuItem variant="destructive" onClick={() => setDeletingRow(row)}>
                    Delete
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        ),
      },
    ],
    [canUpdate, canDelete, recomputeMutation],
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
                <Label>Date</Label>
                <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
              </div>
            </CardContent>
          </Card>

          {!summaryQuery.isLoading && !summaryQuery.isError && (dayRow === null || dayRow.status === 'not_uploaded') ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <p className="text-base font-medium">Mutasi bank belum di upload</p>
                <p className="text-sm text-muted-foreground">{formatDate(date)}</p>
                {canCreate && (
                  <Button onClick={() => navigate('/finance/bank-reconciliation/upload')}>
                    <Upload className="mr-2 h-4 w-4" />
                    Upload Statement
                  </Button>
                )}
              </CardContent>
            </Card>
          ) : (
            <DataTable
              columns={summaryColumns}
              data={summaryQuery.data ?? []}
              rowKey={(row) => row.id}
              isLoading={summaryQuery.isLoading}
              isError={summaryQuery.isError}
              onRetry={() => summaryQuery.refetch()}
              emptyMessage="No data for this date."
            />
          )}
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
                <span className="text-sm font-medium">{formatDate(detailRow.date)}</span>
              </div>
              <ComparisonSubTable date={detailRow.date} />
            </>
          )}
        </TabsContent>
      </Tabs>

      {viewRow && <DayDetailDialog row={viewRow} onClose={() => setViewRow(null)} />}

      {deletingRow && (
        <ConfirmationDialog
          open
          onOpenChange={(open) => !open && setDeletingRow(null)}
          title={`Hapus mutasi bank untuk tanggal ${formatDate(deletingRow.date)}?`}
          description="Data yang sudah di-upload akan hilang dan tidak bisa dikembalikan."
          confirmLabel="Hapus"
          cancelLabel="Batal"
          variant="destructive"
          onConfirm={() => deleteMutation.mutate(deletingRow)}
        />
      )}
    </div>
  )
}
