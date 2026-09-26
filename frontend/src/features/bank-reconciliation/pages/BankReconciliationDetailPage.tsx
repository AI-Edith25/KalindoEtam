import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
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
import { fetchBankReconciliationDetailRows, manualMatchBankStatementLine, recomputeReconciliation } from '../api/bankReconciliationApi'
import type { BankReconciliationDetailRow, BankReconciliationDetailView } from '../types'

const VIEW_LABEL: Record<BankReconciliationDetailView, string> = {
  import: 'Data Import',
  system: 'Data Sistem',
}

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

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

/** Per-transaction reconciliation list -- Import (statement lines) or Sistem (Payment Voucher/Official Receipt), same six-column shape either way. */
export function BankReconciliationDetailPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canUpdate = useHasPermission('finance.bank_reconciliation.update')
  const canCreate = useHasPermission('finance.bank_reconciliation.create')

  const [bankAccountId, setBankAccountId] = useState<string>('')
  const [dateFrom, setDateFrom] = useState(() => todayIso())
  const [dateTo, setDateTo] = useState(() => todayIso())
  const [view, setView] = useState<BankReconciliationDetailView>('import')
  // A wide date range can have hundreds of unmatched rows -- rendering a live SearchableSelect
  // per row for all of them at once is what was actually straining the page, not just this one
  // interaction. Only the row being matched right now mounts its picker.
  const [matchingRowId, setMatchingRowId] = useState<string | null>(null)

  const chartOfAccounts = useQuery({ queryKey: ['chart-of-accounts-lookup'], queryFn: fetchChartOfAccountsLookup })
  const bankAccountOptions = chartOfAccounts.data?.filter((account) => account.is_cash_bank).map((account) => ({ value: account.id, label: account.name })) ?? []

  const rowsQuery = useQuery({
    queryKey: ['bank-reconciliation-detail-rows', bankAccountId, dateFrom, dateTo, view],
    queryFn: () => fetchBankReconciliationDetailRows({ bank_account_id: bankAccountId || undefined, date_from: dateFrom, date_to: dateTo, view }),
  })

  const recomputeMutation = useMutation({
    mutationFn: () => recomputeReconciliation({ bank_account_id: bankAccountId, date_from: dateFrom, date_to: dateTo }),
    onSuccess: () => {
      toast.success('Reconciliation recomputed.')
      queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-detail-rows'] })
    },
    onError: (error) => toastApiError(error),
  })

  const columns = useMemo<DataTableColumn<BankReconciliationDetailRow>[]>(
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
        description="Transactions from uploaded bank statements vs. Payment Voucher/Official Receipt."
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
          <div className="space-y-1.5">
            <Label>View</Label>
            <Select value={view} onValueChange={(value) => setView(value as BankReconciliationDetailView)}>
              <SelectTrigger className="w-40">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="import">{VIEW_LABEL.import}</SelectItem>
                <SelectItem value="system">{VIEW_LABEL.system}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          {canUpdate && (
            <Button
              variant="outline"
              disabled={!bankAccountId || recomputeMutation.isPending}
              title={!bankAccountId ? 'Select a bank account to re-run reconciliation' : undefined}
              onClick={() => recomputeMutation.mutate()}
            >
              <RotateCw className="mr-2 h-4 w-4" />
              Re-run reconciliation
            </Button>
          )}
        </CardContent>
      </Card>

      <DataTable
        columns={columns}
        data={rowsQuery.data ?? []}
        rowKey={(row) => row.id}
        isLoading={rowsQuery.isLoading}
        isError={rowsQuery.isError}
        onRetry={() => rowsQuery.refetch()}
        emptyMessage="No data for this range."
      />
    </div>
  )
}
