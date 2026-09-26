import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { fetchChartOfAccountsLookup } from '@/features/master/api/lookupsApi'
import { fetchPaymentEntries } from '@/features/payment/api/paymentEntryApi'
import { fetchReceiptEntries } from '@/features/payment/api/receiptEntryApi'
import {
  fetchBankReconciliationDetailRows,
  fetchDailyBalancingSummary,
  manualMatchBankStatementLine,
  recomputeReconciliation,
} from '../api/bankReconciliationApi'
import type { BankReconciliationDetailRow, BankReconciliationDetailView, BankReconciliationSummary, BankReconciliationStatus } from '../types'

const VIEW_TABS: { value: BankReconciliationDetailView; label: string }[] = [
  { value: 'import', label: 'Data Import' },
  { value: 'system', label: 'Data Sistem' },
]

/** Payment Voucher/Official Receipt list endpoints already support server-side `search` — reused here instead of a new lookup endpoint. */
function ManualMatchPicker({ row, onMatched }: { row: BankReconciliationDetailRow; onMatched: () => void }) {
  const [documentType, setDocumentType] = useState<'payment_entry' | 'receipt_entry'>(row.direction === 'credit' ? 'payment_entry' : 'receipt_entry')

  const mutation = useMutation({
    mutationFn: (documentId: string) => manualMatchBankStatementLine(row.bank_statement_line_id!, documentType, documentId),
    onSuccess: () => { toast.success('Line matched.'); onMatched() },
    onError: (error) => toastApiError(error),
  })

  const loadOptions = async (search: string) => {
    if (documentType === 'payment_entry') {
      const { data } = await fetchPaymentEntries({ page: 1, per_page: 10, search })
      return data.map((entry) => ({ value: entry.id, label: `${entry.document_number} — ${formatCurrency(entry.total_amount)}` }))
    }
    const { data } = await fetchReceiptEntries({ page: 1, per_page: 10, search })
    return data.map((entry) => ({ value: entry.id, label: `${entry.document_number} — ${formatCurrency(entry.total_amount)}` }))
  }

  return (
    <div className="flex items-center gap-1">
      <Select value={documentType} onValueChange={(value) => setDocumentType(value as typeof documentType)}>
        <SelectTrigger className="h-8 w-28 text-xs">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="payment_entry">Payment Voucher</SelectItem>
          <SelectItem value="receipt_entry">Official Receipt</SelectItem>
        </SelectContent>
      </Select>
      <SearchableSelect
        key={documentType}
        loadOptions={loadOptions}
        onChange={(value) => value && mutation.mutate(value)}
        placeholder="Find document..."
        loading={mutation.isPending}
        className="h-8 w-56"
      />
    </div>
  )
}

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

/**
 * Daily balancing table (one row per bank account + day, no Bank Account column since the filter
 * above already picks it) -- select a day to open its transaction-level detail below, either side:
 * "Data Import" (uploaded statement lines) or "Data Sistem" (Payment Voucher/Official Receipt).
 */
export function BankReconciliationDetailPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canUpdate = useHasPermission('finance.bank_reconciliation.update')
  const canCreate = useHasPermission('finance.bank_reconciliation.create')

  const [bankAccountId, setBankAccountId] = useState<string>('')
  const [dateFrom, setDateFrom] = useState(() => todayIso())
  const [dateTo, setDateTo] = useState(() => todayIso())
  const [selectedRow, setSelectedRow] = useState<BankReconciliationSummary | null>(null)
  const [view, setView] = useState<BankReconciliationDetailView>('import')
  // One selected day's unmatched rows stay in the low tens at most -- but only the row actually
  // being matched right now mounts its SearchableSelect, not all of them at once regardless.
  const [matchingRowId, setMatchingRowId] = useState<string | null>(null)

  const chartOfAccounts = useQuery({ queryKey: ['chart-of-accounts-lookup'], queryFn: fetchChartOfAccountsLookup })
  const bankAccountOptions = chartOfAccounts.data?.filter((account) => account.is_cash_bank).map((account) => ({ value: account.id, label: account.name })) ?? []

  const summaryQuery = useQuery({
    queryKey: ['bank-reconciliation-summary', bankAccountId, dateFrom, dateTo],
    queryFn: () => fetchDailyBalancingSummary({ bank_account_id: bankAccountId || undefined, date_from: dateFrom, date_to: dateTo }),
  })

  const detailRowsQuery = useQuery({
    queryKey: ['bank-reconciliation-detail-rows', selectedRow?.bank_account_id, selectedRow?.date, view],
    queryFn: () =>
      fetchBankReconciliationDetailRows({
        bank_account_id: selectedRow!.bank_account_id,
        date_from: selectedRow!.date,
        date_to: selectedRow!.date,
        view,
      }),
    enabled: selectedRow !== null && selectedRow.status !== 'not_uploaded',
  })

  const recomputeMutation = useMutation({
    mutationFn: (row: BankReconciliationSummary) => recomputeReconciliation({ bank_account_id: row.bank_account_id, date_from: row.date, date_to: row.date }),
    onSuccess: () => {
      toast.success('Reconciliation recomputed.')
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-summary'] })
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-detail-rows'] })
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
        accessor: (row) =>
          canUpdate && row.status !== 'not_uploaded' ? (
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); recomputeMutation.mutate(row) }} disabled={recomputeMutation.isPending}>
              <RotateCw className="h-4 w-4" />
            </Button>
          ) : null,
      },
    ],
    [canUpdate, recomputeMutation],
  )

  const detailColumns = useMemo<DataTableColumn<BankReconciliationDetailRow>[]>(
    () => [
      { header: 'Date', accessor: (row) => formatDate(row.date) },
      { header: 'Customer', accessor: (row) => row.customer ?? '-' },
      { header: 'System', accessor: (row) => (row.system_amount !== null ? formatCurrency(row.system_amount) : '-'), className: 'text-right' },
      { header: 'Statement', accessor: (row) => (row.statement_amount !== null ? formatCurrency(row.statement_amount) : '-'), className: 'text-right' },
      { header: 'Selisih', accessor: (row) => formatCurrency(row.selisih), className: 'text-right' },
      {
        header: 'Status',
        accessor: (row) => (
          <div className="space-y-1">
            <Badge variant={row.status === 'unmatched' ? 'destructive' : 'default'}>{row.status}</Badge>
            {view === 'import' && row.status === 'unmatched' && canUpdate && (
              matchingRowId === row.id ? (
                <ManualMatchPicker
                  row={row}
                  onMatched={() => { setMatchingRowId(null); queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-detail-rows'] }) }}
                />
              ) : (
                <Button size="sm" variant="link" className="h-auto p-0 text-xs" onClick={() => setMatchingRowId(row.id)}>
                  Match
                </Button>
              )
            )}
          </div>
        ),
      },
    ],
    [view, canUpdate, queryClient, matchingRowId],
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

      <Card>
        <CardContent className="flex flex-wrap items-end gap-4 pt-6">
          <div className="space-y-1.5">
            <Label>Bank Account</Label>
            <Select value={bankAccountId || 'all'} onValueChange={(value) => setBankAccountId(value === 'all' ? '' : value)}>
              <SelectTrigger className="w-56">
                <SelectValue placeholder="All bank accounts" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All bank accounts</SelectItem>
                {bankAccountOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
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
        onRowClick={(row) => setSelectedRow(row)}
        emptyMessage="No data for this range."
      />

      {selectedRow && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {selectedRow.bank_account_name} &mdash; {formatDate(selectedRow.date)}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {selectedRow.status === 'not_uploaded' ? (
              <p className="text-sm text-muted-foreground">{STATUS_LABEL.not_uploaded}</p>
            ) : (
              <>
                <div className="flex items-center gap-1 rounded-md border p-1">
                  {VIEW_TABS.map((tab) => (
                    <Button key={tab.value} size="sm" variant={view === tab.value ? 'default' : 'ghost'} onClick={() => setView(tab.value)}>
                      {tab.label}
                    </Button>
                  ))}
                </div>
                <DataTable
                  columns={detailColumns}
                  data={detailRowsQuery.data ?? []}
                  rowKey={(row) => row.id}
                  isLoading={detailRowsQuery.isLoading}
                  isError={detailRowsQuery.isError}
                  onRetry={() => detailRowsQuery.refetch()}
                  emptyMessage="No transactions for this day."
                />
              </>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
