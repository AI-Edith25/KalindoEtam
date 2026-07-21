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
import { getErrorMessage } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { deleteCreditNote, fetchCreditNotes, reverseCreditNote, submitCreditNote } from '../api/creditNoteApi'
import { CreditNoteFiltersBar } from '../components/CreditNoteFiltersBar'
import { emptyCreditNoteFilters } from '../lib/creditNoteFilters'
import { CREDIT_NOTE_REASON_LABELS } from '../lib/creditNoteReasonLabels'
import { salesSectionNav } from '../navigation'
import type { CreditNote, CreditNoteFilterValues } from '../types'

export function CreditNoteListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('credit_note.create')
  const canUpdate = useHasPermission('credit_note.update')
  const canDelete = useHasPermission('credit_note.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<CreditNoteFilterValues>(emptyCreditNoteFilters)
  const [deletingCreditNote, setDeletingCreditNote] = useState<CreditNote | null>(null)

  const listQuery = useQuery({
    queryKey: ['credit-notes', page, search, filters.status, filters.reason, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchCreditNotes({
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
    queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
    queryClient.invalidateQueries({ queryKey: ['invoices'] })
  }

  const submitMutation = useMutation({
    mutationFn: submitCreditNote,
    onSuccess: () => {
      invalidate()
      toast.success('Credit Note submitted — Accounts Receivable updated.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const reverseMutation = useMutation({
    mutationFn: reverseCreditNote,
    onSuccess: () => {
      invalidate()
      toast.success('Credit Note reversed.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCreditNote,
    onSuccess: () => {
      invalidate()
      toast.success('Credit Note deleted.')
      setDeletingCreditNote(null)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const rows = listQuery.data?.data ?? []

  const actionsFor = (creditNote: CreditNote): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/sales/credit-notes/${creditNote.id}`) }]

    if (creditNote.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/credit-notes/${creditNote.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(creditNote.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingCreditNote(creditNote) })
      }
    } else if (creditNote.status === 'submitted' && !creditNote.is_reversed && canUpdate) {
      actions.push({ label: 'Reverse', icon: RotateCcw, variant: 'destructive', onClick: () => reverseMutation.mutate(creditNote.id) })
    }

    return actions
  }

  const columns: DataTableColumn<CreditNote>[] = [
    { header: 'Credit Note No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Invoice', accessor: (row) => row.invoice?.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Reason', accessor: (row) => CREDIT_NOTE_REASON_LABELS[row.reason] },
    { header: 'Date', accessor: (row) => formatDate(row.credit_note_date) },
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
        title="Credit Notes"
        description="The only accounting-correction path for a posted Invoice — corrections never flow through cancelling the Invoice itself."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} credit notes` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Credit Note', icon: Plus, onClick: () => navigate('/sales/credit-notes/new') } : undefined}
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
          placeholder="Search credit note number, invoice, or customer…"
        />
        <CreditNoteFiltersBar
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
        emptyMessage={hasFilters ? 'No credit notes match your search or filters.' : 'No credit notes yet.'}
        onRowClick={(row) => navigate(`/sales/credit-notes/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingCreditNote}
        onOpenChange={(open) => !open && setDeletingCreditNote(null)}
        itemLabel={deletingCreditNote?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingCreditNote) deleteMutation.mutate(deletingCreditNote.id)
        }}
      />
    </div>
  )
}
