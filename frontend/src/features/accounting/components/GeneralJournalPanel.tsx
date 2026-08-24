import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, RotateCw } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatCurrency, formatDate } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportJournalEntries, fetchJournalEntries } from '../api/journalEntryApi'
import { JournalEntryFiltersBar } from './JournalEntryFiltersBar'
import type { JournalEntry, JournalEntryFilterValues } from '../types'

interface GeneralJournalPanelProps {
  search: string
  onSearchChange: (search: string) => void
  filters: JournalEntryFilterValues
  onFiltersChange: (filters: JournalEntryFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

/**
 * General Journal — read-only mirror of /finance/general-journal's own list
 * (JournalEntryListPage), same columns minus row actions/selection/New/Post.
 * No backend change: reuses fetchJournalEntries/exportJournalEntries as-is —
 * this is purely another read surface over already-paginated, already-
 * exportable data.
 */
export function GeneralJournalPanel({ search, onSearchChange, filters, onFiltersChange, page, onPageChange }: GeneralJournalPanelProps) {
  const navigate = useNavigate()
  const [isExporting, setIsExporting] = useState(false)

  const activeFilterParams = {
    ...(search ? { search } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.referenceType ? { reference_type: filters.referenceType } : {}),
    ...(filters.accountId ? { account_id: filters.accountId } : {}),
    ...(filters.branchId ? { branch_id: filters.branchId } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['journal-entries', page, search, filters.status, filters.referenceType, filters.accountId, filters.branchId, filters.dateFrom, filters.dateTo],
    queryFn: () => fetchJournalEntries({ page, ...activeFilterParams }),
    placeholderData: (previous) => previous,
  })

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportJournalEntries(activeFilterParams, format)
      downloadBlob(`general-journal.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const rows = listQuery.data?.data ?? []

  const columns: DataTableColumn<JournalEntry>[] = [
    { header: 'Journal Number', accessor: (row) => row.document_number ?? '—' },
    { header: 'Posting Date', accessor: (row) => formatDate(row.posting_date) },
    { header: 'Reference Type', accessor: (row) => row.reference_label ?? 'Manual' },
    { header: 'Reference Number', accessor: (row) => row.reference_document_number ?? '—' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status === 'submitted' ? 'posted' : row.status} /> },
    { header: 'Total Debit', accessor: (row) => formatCurrency(row.total_debit), className: 'text-right' },
    { header: 'Total Credit', accessor: (row) => formatCurrency(row.total_credit), className: 'text-right' },
    { header: 'Created By', accessor: (row) => row.created_by_name ?? '—' },
  ]

  const hasFilters = !!(
    search || filters.status || filters.referenceType || filters.accountId || filters.branchId || filters.dateFrom || filters.dateTo
  )

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
        <SearchBox
          value={search}
          onChange={(value) => {
            onSearchChange(value)
            onPageChange(1)
          }}
          placeholder="Search journal number or description…"
        />
        <JournalEntryFiltersBar
          value={filters}
          onChange={(value) => {
            onFiltersChange(value)
            onPageChange(1)
          }}
        />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No journal entries match your search or filters.' : 'No journal entries yet.'}
        onRowClick={(row) => navigate(`/finance/general-journal/journal-entries/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
