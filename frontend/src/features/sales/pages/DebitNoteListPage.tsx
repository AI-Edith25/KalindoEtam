import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Download, Eye, Pencil, Plus, RotateCcw, RotateCw, Send, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { deleteDebitNote, fetchDebitNotes, reverseDebitNote, submitDebitNote } from '../api/debitNoteApi'
import { DebitNoteFiltersBar } from '../components/DebitNoteFiltersBar'
import { emptyDebitNoteFilters } from '../lib/debitNoteFilters'
import { DEBIT_NOTE_REASON_LABELS } from '../lib/debitNoteReasonLabels'
import { salesSectionNav } from '../navigation'
import type { DebitNote, DebitNoteFilterValues } from '../types'

export function DebitNoteListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('debit_note.create')
  const canUpdate = useHasPermission('debit_note.update')
  const canDelete = useHasPermission('debit_note.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<DebitNoteFilterValues>(emptyDebitNoteFilters)
  const [deletingDebitNote, setDeletingDebitNote] = useState<DebitNote | null>(null)

  const listQuery = useQuery({
    queryKey: ['debit-notes', page, search, filters.status, filters.reason, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchDebitNotes({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.reason ? { reason: filters.reason } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['debit-notes'] })
    queryClient.invalidateQueries({ queryKey: ['invoices'] })
  }

  const submitMutation = useMutation({
    mutationFn: submitDebitNote,
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note submitted — Accounts Receivable updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const reverseMutation = useMutation({
    mutationFn: reverseDebitNote,
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note reversed.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteDebitNote,
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note deleted.')
      setDeletingDebitNote(null)
    },
    onError: (error) => toastApiError(error),
  })

  const rows = listQuery.data?.data ?? []

  const actionsFor = (debitNote: DebitNote): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/sales/debit-notes/${debitNote.id}`) }]

    if (debitNote.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/debit-notes/${debitNote.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(debitNote.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingDebitNote(debitNote) })
      }
    } else if (debitNote.status === 'submitted' && !debitNote.is_reversed && canUpdate) {
      actions.push({ label: 'Reverse', icon: RotateCcw, variant: 'destructive', onClick: () => reverseMutation.mutate(debitNote.id) })
    }

    return actions
  }

  const columns: DataTableColumn<DebitNote>[] = [
    { header: 'Debit Note No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Invoice', accessor: (row) => row.invoice?.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Reason', accessor: (row) => DEBIT_NOTE_REASON_LABELS[row.reason] },
    { header: 'Date', accessor: (row) => formatDate(row.debit_note_date) },
    { header: 'Amount', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    {
      header: 'Status',
      accessor: (row) => (
        <div className="flex items-center gap-2">
          <StatusBadge status={row.status} />
          {row.is_reversed && <Badge variant="secondary">Reversed</Badge>}
        </div>
      ),
    },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.reason || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={salesSectionNav} />

      <PageHeader
        title="Debit Notes"
        description="Increases a customer's receivable after a posted Invoice — under-billed quantities, price corrections, additional charges, freight, or tax."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} debit notes` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Debit Note', icon: Plus, onClick: () => navigate('/sales/debit-notes/new') } : undefined}
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
          placeholder="Search debit note number, invoice, or customer…"
        />
        <DebitNoteFiltersBar
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
        emptyMessage={hasFilters ? 'No debit notes match your search or filters.' : 'No debit notes yet.'}
        onRowClick={(row) => navigate(`/sales/debit-notes/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingDebitNote}
        onOpenChange={(open) => !open && setDeletingDebitNote(null)}
        itemLabel={deletingDebitNote?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingDebitNote) deleteMutation.mutate(deletingDebitNote.id)
        }}
      />
    </div>
  )
}
