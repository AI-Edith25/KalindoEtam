import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { LedgerImportReportDialog } from '@/features/payment/components/LedgerImportReportDialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchGeneralLedgerImportBatch, fetchLedgerAccounts, importGeneralLedger } from '../api/generalLedgerApi'
import { GeneralLedgerFiltersBar } from '../components/GeneralLedgerFiltersBar'
import { emptyGeneralLedgerFilters } from '../lib/generalLedgerFilters'
import type { GeneralLedgerFilterValues, LedgerAccountSummary } from '../types'

/** A read model — no create/edit/delete anywhere on this page, Opening Balance import excepted (posts a real Journal Entry, see PrintLedgerImportService). See docs/GENERAL_LEDGER_DESIGN.md §1. */
export function GeneralLedgerListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canImport = useHasPermission('accounting.general_ledger.import')
  const [filters, setFilters] = useState<GeneralLedgerFilterValues>(emptyGeneralLedgerFilters)
  const [importBatchId, setImportBatchId] = useState<string | null>(null)
  const importFileInputRef = useRef<HTMLInputElement>(null)

  const listQuery = useQuery({
    queryKey: ['general-ledger-accounts', filters.status, filters.referenceType, filters.branchId, filters.companyId, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchLedgerAccounts({
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.referenceType ? { reference_type: filters.referenceType } : {}),
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.companyId ? { company_id: filters.companyId } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const importMutation = useMutation({
    mutationFn: importGeneralLedger,
    onSuccess: (batch) => setImportBatchId(batch.id),
    onError: (error) => toastApiError(error),
  })

  const rows = listQuery.data ?? []

  const columns: DataTableColumn<LedgerAccountSummary>[] = [
    { header: 'Code', accessor: (row) => row.code, className: 'text-muted-foreground' },
    { header: 'Name', accessor: (row) => row.name },
    { header: 'Type', accessor: (row) => <span className="capitalize">{row.account_type}</span>, className: 'text-muted-foreground' },
    { header: 'Opening Balance', accessor: (row) => formatCurrency(row.opening_balance), className: 'text-right font-medium' },
    { header: 'Debit', accessor: (row) => formatCurrency(row.debit), className: 'text-right font-medium' },
    { header: 'Credit', accessor: (row) => formatCurrency(row.credit), className: 'text-right font-medium' },
    { header: 'Ending Balance', accessor: (row) => formatCurrency(row.ending_balance), className: 'text-right font-medium' },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Print Ledger"
        description="A read-only report of every account's balance for the selected period — opening, debit, credit, and ending — derived entirely from posted Journal Entries, never a separate ledger table. For account master data, see Chart of Accounts."
        count={rows.length ? `${formatNumber(rows.length)} accounts` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              {
                label: importMutation.isPending ? 'Mengunggah…' : 'Import',
                icon: Upload,
                disabled: !canImport || importMutation.isPending,
                onClick: () => importFileInputRef.current?.click(),
              },
            ]}
          />
        }
      />

      <input
        ref={importFileInputRef}
        type="file"
        accept=".csv,.xlsx,.xls"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0]
          event.target.value = ''
          if (file) importMutation.mutate(file)
        }}
      />

      <SectionNav group="accounting" variant="pills" end />

      <div className="flex flex-wrap items-center gap-3">
        <GeneralLedgerFiltersBar value={filters} onChange={setFilters} variant="list" />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage="No account activity matches these filters."
        onRowClick={(row) => navigate(`/reports/general-ledger/${row.id}`)}
      />

      <LedgerImportReportDialog
        title="Import Print Ledger (Opening Balance)"
        batchId={importBatchId}
        fetchBatch={fetchGeneralLedgerImportBatch}
        onClose={() => {
          setImportBatchId(null)
          queryClient.invalidateQueries({ queryKey: ['general-ledger-accounts'] })
        }}
      />
    </div>
  )
}
