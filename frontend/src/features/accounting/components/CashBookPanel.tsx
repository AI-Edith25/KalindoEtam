import { useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, RotateCw, Upload } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { ConfirmationDialog } from '@/components/shared/ConfirmationDialog'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { LedgerImportReportDialog } from '@/features/payment/components/LedgerImportReportDialog'
import { formatCurrency, formatDate } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { getErrorMessage, isJournalTypeMismatch, toastApiError } from '@/shared/services/errorHandler'
import { fetchCashBook, fetchCashBookImportBatch, importCashBook } from '../api/cashBookApi'
import { exportJournalList, journalListFileName } from '../api/journalListApi'
import { CashBookFiltersBar } from './CashBookFiltersBar'
import type { CashBookFilterValues, CashBookRow, CashBookView } from '../types'

interface CashBookPanelProps {
  view: CashBookView
  search: string
  onSearchChange: (search: string) => void
  filters: CashBookFilterValues
  onFiltersChange: (filters: CashBookFilterValues) => void
  page: number
  onPageChange: (page: number) => void
  /** The Journal Type select — which of Cash Book's 3 views this is comes from there now, not an in-panel toggle. */
  journalTypeSelect: ReactNode
}

/**
 * Cash Book Transaction — one page, one table; `view` changes only the
 * table's contents, filters/pagination/export stay in place. All URL-synced
 * state (view/search/filters/page) is owned by JournalListPage — this
 * component is a pure display + fetch layer.
 */
export function CashBookPanel({ view, search, onSearchChange, filters, onFiltersChange, page, onPageChange, journalTypeSelect }: CashBookPanelProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canImport = useHasPermission('accounting.journal_list.import')
  const [isExporting, setIsExporting] = useState(false)
  const [importBatchId, setImportBatchId] = useState<string | null>(null)
  const [mismatchConfirm, setMismatchConfirm] = useState<{ message: string; file: File } | null>(null)
  const importFileInputRef = useRef<HTMLInputElement>(null)

  const activeParams = {
    view,
    ...(search ? { search } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.branchId ? { branch_id: filters.branchId } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['cash-book', page, view, search, filters.status, filters.branchId, filters.dateFrom, filters.dateTo],
    queryFn: () => fetchCashBook({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportJournalList({ ...activeParams, format })
      downloadBlob(journalListFileName(view, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const importMutation = useMutation({
    mutationFn: ({ file, confirmJournalType }: { file: File; confirmJournalType: boolean }) => importCashBook(file, view, confirmJournalType),
    onSuccess: (batch) => {
      setImportBatchId(batch.id)
      setMismatchConfirm(null)
    },
    onError: (error, variables) => {
      if (isJournalTypeMismatch(error)) {
        setMismatchConfirm({ message: getErrorMessage(error), file: variables.file })
        return
      }
      toastApiError(error)
    },
  })

  const rows = listQuery.data?.data ?? []

  const columns: DataTableColumn<CashBookRow>[] = [
    { header: 'Document', accessor: (row) => row.document_number ?? '—' },
    { header: 'Type', accessor: (row) => <StatusBadge status={row.type} /> },
    { header: 'Party', accessor: (row) => row.party_name ?? '—' },
    { header: 'Payment Method', accessor: (row) => row.payment_method_name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.date) },
    { header: 'Debit', accessor: (row) => (row.debit > 0 ? formatCurrency(row.debit) : '—'), className: 'text-right' },
    { header: 'Credit', accessor: (row) => (row.credit > 0 ? formatCurrency(row.credit) : '—'), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  ]

  // Extra columns follow /finance/incoming (Unallocated) and /finance/outgoing (Reference) — only
  // meaningful once the view is narrowed to one document type.
  if (view === 'receipt') {
    columns.push({
      header: 'Unallocated',
      accessor: (row) => (row.status === 'submitted' ? formatCurrency(row.unallocated) : '—'),
      className: 'text-right',
    })
  } else if (view === 'payment') {
    columns.push({ header: 'Reference', accessor: (row) => row.reference_number ?? '—' })
  }

  const hasFilters = !!(search || filters.status || filters.branchId || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex justify-end">
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting },
            { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting },
            {
              label: importMutation.isPending ? 'Mengunggah…' : 'Import',
              icon: Upload,
              disabled: !canImport || importMutation.isPending,
              onClick: () => importFileInputRef.current?.click(),
            },
          ]}
        />
      </div>

      <input
        ref={importFileInputRef}
        type="file"
        accept=".csv,.xlsx,.xls"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0]
          event.target.value = ''
          if (file) importMutation.mutate({ file, confirmJournalType: false })
        }}
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={search} onChange={onSearchChange} placeholder="Search document number or party…" />
        <CashBookFiltersBar value={filters} onChange={onFiltersChange} leading={journalTypeSelect} />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No transactions match your search or filters.' : 'No cash/bank transactions yet.'}
        onRowClick={(row) => navigate(row.type === 'receipt' ? `/finance/incoming/${row.id}` : `/finance/outgoing/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}

      <ConfirmationDialog
        open={!!mismatchConfirm}
        onOpenChange={(open) => !open && setMismatchConfirm(null)}
        title="File sepertinya untuk Journal Type lain"
        description={mismatchConfirm?.message}
        confirmLabel="Lanjutkan Import"
        onConfirm={() => {
          if (mismatchConfirm) importMutation.mutate({ file: mismatchConfirm.file, confirmJournalType: true })
        }}
      />

      <LedgerImportReportDialog
        title="Import Cash Book"
        batchId={importBatchId}
        fetchBatch={fetchCashBookImportBatch}
        onClose={() => {
          setImportBatchId(null)
          queryClient.invalidateQueries({ queryKey: ['cash-book'] })
        }}
      />
    </div>
  )
}
