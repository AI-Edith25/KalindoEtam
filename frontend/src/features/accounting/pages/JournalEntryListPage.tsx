import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, Plus, RotateCw, Send, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { fetchJournalEntries, postJournalEntry } from '../api/journalEntryApi'
import { JournalEntryFiltersBar } from '../components/JournalEntryFiltersBar'
import { emptyJournalEntryFilters } from '../lib/journalEntryFilters'
import type { JournalEntry, JournalEntryFilterValues } from '../types'

/** Journal Search + Journal Filters (the ticket's terms) are this page's search box + filter bar, not separate routes — matching every other module's convention. */
export function JournalEntryListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('accounting.journal_entries.create')
  const canUpdate = useHasPermission('accounting.journal_entries.update')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<JournalEntryFilterValues>(emptyJournalEntryFilters)

  const listQuery = useQuery({
    queryKey: ['journal-entries', page, search, filters.status, filters.referenceType, filters.accountId, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchJournalEntries({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.referenceType ? { reference_type: filters.referenceType } : {}),
        ...(filters.accountId ? { account_id: filters.accountId } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const postMutation = useMutation({
    mutationFn: postJournalEntry,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
      toast.success('Journal Entry posted.')
    },
    onError: (error) => toastApiError(error),
  })

  const rows = listQuery.data?.data ?? []

  const actionsFor = (entry: JournalEntry): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/accounting/journal-entries/${entry.id}`) }]

    if (entry.status === 'draft' && canUpdate) {
      actions.push({ label: 'Post', icon: Send, onClick: () => postMutation.mutate(entry.id) })
    }

    return actions
  }

  const columns: DataTableColumn<JournalEntry>[] = [
    { header: 'Journal Number', accessor: (row) => row.document_number ?? '—' },
    { header: 'Posting Date', accessor: (row) => formatDate(row.posting_date) },
    { header: 'Reference Type', accessor: (row) => row.reference_label ?? 'Manual' },
    { header: 'Reference Number', accessor: (row) => row.reference_document_number ?? '—' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status === 'submitted' ? 'posted' : row.status} /> },
    { header: 'Total Debit', accessor: (row) => formatCurrency(row.total_debit), className: 'text-right' },
    { header: 'Total Credit', accessor: (row) => formatCurrency(row.total_credit), className: 'text-right' },
    { header: 'Created By', accessor: (row) => row.created_by_name ?? '—' },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.referenceType || filters.accountId || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="accounting" />

      <PageHeader
        title="Journal Entries"
        description="The General Ledger — every posted debit/credit, system-generated or manually posted."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} entries` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Journal Entry', icon: Plus, onClick: () => navigate('/accounting/journal-entries/new') } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search journal number or description…"
        />
        <JournalEntryFiltersBar
          value={filters}
          onChange={(value) => {
            setFilters(value)
            setPage(1)
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
        onRowClick={(row) => navigate(`/accounting/journal-entries/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
