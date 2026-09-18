import { useRef, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, RotateCw, Upload } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { Checkbox } from '@/components/ui/checkbox'
import { ConfirmationDialog } from '@/components/shared/ConfirmationDialog'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { LedgerImportReportDialog } from '@/features/payment/components/LedgerImportReportDialog'
import { formatCurrency, formatDate } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { getErrorMessage, isJournalTypeMismatch, toastApiError } from '@/shared/services/errorHandler'
import { exportSalesJournal, fetchSalesJournal, salesJournalFileName } from '../api/salesJournalApi'
import { fetchSalesPurchaseJournalImportBatch, importSalesPurchaseJournal } from '../api/salesPurchaseJournalImportApi'
import { SalesJournalFiltersBar } from './SalesJournalFiltersBar'
import type { SalesJournalFilterValues, SalesJournalView } from '../types'
import type { SalesListingRow } from '@/features/reports/types'

interface SalesJournalPanelProps {
  view: SalesJournalView
  search: string
  onSearchChange: (search: string) => void
  filters: SalesJournalFilterValues
  onFiltersChange: (filters: SalesJournalFilterValues) => void
  page: number
  onPageChange: (page: number) => void
  /** The Journal Type select — which of Sales Journal's 2 views this is comes from there now, not an in-panel toggle. */
  journalTypeSelect: ReactNode
}

/**
 * Sales Journal — Sales Invoice/Credit Note, `view` changes only the table's contents (Journal
 * Type in the filter row picks it, not an in-panel toggle). Screen rows reuse SalesListingRow's
 * shape as-is — SalesJournalRepository's screen query is SalesListingRepository::query() pinned
 * to one type.
 */
export function SalesJournalPanel({ view, search, onSearchChange, filters, onFiltersChange, page, onPageChange, journalTypeSelect }: SalesJournalPanelProps) {
  const queryClient = useQueryClient()
  const canImport = useHasPermission('accounting.journal_list.import')
  const [isExporting, setIsExporting] = useState(false)
  const [importBatchId, setImportBatchId] = useState<string | null>(null)
  const [createDuplicatesAnyway, setCreateDuplicatesAnyway] = useState(false)
  const [mismatchConfirm, setMismatchConfirm] = useState<{ message: string; file: File } | null>(null)
  const importFileInputRef = useRef<HTMLInputElement>(null)

  const activeParams = {
    view,
    ...(search ? { search } : {}),
    ...(filters.branchId ? { branch_id: filters.branchId } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['sales-journal', page, view, search, filters.branchId, filters.dateFrom, filters.dateTo],
    queryFn: () => fetchSalesJournal({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportSalesJournal({ ...activeParams, format })
      downloadBlob(salesJournalFileName(view, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const importView = view === 'credit_note' ? 'sales_credit_note' : 'sales_invoice'

  const importMutation = useMutation({
    mutationFn: ({ file, confirmJournalType }: { file: File; confirmJournalType: boolean }) =>
      importSalesPurchaseJournal(file, importView, confirmJournalType, createDuplicatesAnyway ? 'create_anyway' : 'skip'),
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

  const columns: DataTableColumn<SalesListingRow>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.date) },
    { header: 'Document', accessor: (row) => row.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer_name },
    { header: 'Amount Excl. Tax', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
    { header: 'Tax', accessor: (row) => formatCurrency(row.tax), className: 'text-right' },
    { header: 'Amount Incl. Tax', accessor: (row) => formatCurrency(row.amount_incl_tax), className: 'text-right' },
  ]

  const hasFilters = !!(search || filters.branchId || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-end gap-3">
        {canImport && (
          <label className="flex items-center gap-2 text-sm text-muted-foreground">
            <Checkbox checked={createDuplicatesAnyway} onCheckedChange={(checked) => setCreateDuplicatesAnyway(checked === true)} />
            Buat entry baru untuk nomor dokumen yang sudah pernah diimpor
          </label>
        )}
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
        <SearchBox value={search} onChange={onSearchChange} placeholder="Search document number…" />
        <SalesJournalFiltersBar value={filters} onChange={onFiltersChange} leading={journalTypeSelect} />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No transactions match your search or filters.' : view === 'credit_note' ? 'No credit notes yet.' : 'No sales invoices yet.'}
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
        title={view === 'credit_note' ? 'Import Sales Journal Credit Note' : 'Import Sales Journal Invoice'}
        batchId={importBatchId}
        fetchBatch={fetchSalesPurchaseJournalImportBatch}
        onClose={() => {
          setImportBatchId(null)
          // These entries never appear in THIS list (Sales Journal's screen reads from the Invoice/
          // CreditNote tables, not journal_entries — see the service's own docblock for why); they
          // land in General Journal instead, so that's the query to refresh, not this panel's own.
          queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
        }}
      />
    </div>
  )
}
