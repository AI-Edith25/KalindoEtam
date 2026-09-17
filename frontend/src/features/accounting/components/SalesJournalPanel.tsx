import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, RotateCw } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { formatCurrency, formatDate } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportSalesJournal, fetchSalesJournal, salesJournalFileName } from '../api/salesJournalApi'
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
  const [isExporting, setIsExporting] = useState(false)

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
      <div className="flex justify-end">
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting },
            { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting },
          ]}
        />
      </div>

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
    </div>
  )
}
