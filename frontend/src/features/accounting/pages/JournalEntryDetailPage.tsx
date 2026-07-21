import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ExternalLink, Loader2, Pencil, RotateCcw, Send, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { deleteJournalEntry, fetchJournalEntry, postJournalEntry, reverseJournalEntry } from '../api/journalEntryApi'
import { resolveJournalReferenceLink } from '../lib/journalReferenceLink'
import { ApprovalPanel } from '@/features/approval/components/ApprovalPanel'
import type { JournalEntryLine } from '../types'

const APPROVABLE_TYPE = 'App\\Models\\JournalEntry'

const lineColumns: DataTableColumn<JournalEntryLine>[] = [
  { header: 'Account', accessor: (row) => (row.chart_of_account ? `${row.chart_of_account.code} — ${row.chart_of_account.name}` : '—') },
  { header: 'Description', accessor: (row) => row.description || '—' },
  { header: 'Debit', accessor: (row) => (Number(row.debit) > 0 ? formatCurrency(row.debit) : '—'), className: 'text-right' },
  { header: 'Credit', accessor: (row) => (Number(row.credit) > 0 ? formatCurrency(row.credit) : '—'), className: 'text-right' },
]

export function JournalEntryDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const entryQuery = useQuery({
    queryKey: ['journal-entries', id],
    queryFn: () => fetchJournalEntry(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['journal-entries'] })

  const postMutation = useMutation({
    mutationFn: () => postJournalEntry(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Journal Entry posted.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const reverseMutation = useMutation({
    mutationFn: () => reverseJournalEntry(id!),
    onSuccess: (reversal) => {
      invalidate()
      toast.success('Journal Entry reversed.')
      navigate(`/accounting/journal-entries/${reversal.id}`)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteJournalEntry(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Journal Entry deleted.')
      navigate('/accounting/journal-entries')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (entryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const entry = entryQuery.data
  if (!entry) return null

  const referenceLink = entry.reference_label ? resolveJournalReferenceLink(entry.reference_label, entry.reference_id!) : null
  const blockedByApproval = entry.requires_approval && entry.latest_approval?.status !== 'approved'

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={entry.document_number ?? 'Journal Entry'}
        description="Journal Entry details."
        actions={
          <div className="flex items-center gap-2">
            {entry.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/accounting/journal-entries/${entry.id}/edit`)}>
                  <Pencil className="size-4" />
                  Edit
                </Button>
                <Button
                  onClick={() => postMutation.mutate()}
                  disabled={postMutation.isPending || blockedByApproval}
                  title={blockedByApproval ? 'This manual entry needs an approved request before it can be posted.' : undefined}
                >
                  {postMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                  Post
                </Button>
                <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                  <Trash2 className="size-4" />
                  Delete
                </Button>
              </>
            )}
            {entry.status === 'submitted' && !entry.reversed_by_id && (
              <Button variant="destructive" onClick={() => reverseMutation.mutate()} disabled={reverseMutation.isPending}>
                {reverseMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <RotateCcw className="size-4" />}
                Reverse
              </Button>
            )}
          </div>
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Entry Information</CardTitle>
          <StatusBadge status={entry.status === 'submitted' ? 'posted' : entry.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Journal Number" value={entry.document_number ?? '—'} />
            <DetailField label="Posting Date" value={formatDate(entry.posting_date)} />
            <DetailField
              label="Reference"
              value={
                entry.reference_label ? (
                  referenceLink ? (
                    <Button variant="link" className="h-auto p-0" onClick={() => navigate(referenceLink)}>
                      {entry.reference_label}: {entry.reference_document_number ?? '—'}
                      <ExternalLink className="size-3.5" />
                    </Button>
                  ) : (
                    `${entry.reference_label}: ${entry.reference_document_number ?? '—'}`
                  )
                ) : (
                  'Manual'
                )
              }
            />
            <DetailField label="Created By" value={entry.created_by_name ?? '—'} />
            <DetailField label="Description" value={entry.description || '—'} />
            {entry.reverses_id && (
              <DetailField
                label="Reverses"
                value={
                  <Button variant="link" className="h-auto p-0" onClick={() => navigate(`/accounting/journal-entries/${entry.reverses_id}`)}>
                    {entry.reverses_document_number ?? '—'}
                    <ExternalLink className="size-3.5" />
                  </Button>
                }
              />
            )}
            {entry.reversed_by_id && (
              <DetailField
                label="Reversed By"
                value={
                  <Button
                    variant="link"
                    className="h-auto p-0"
                    onClick={() => navigate(`/accounting/journal-entries/${entry.reversed_by_id}`)}
                  >
                    {entry.reversed_by_document_number ?? '—'}
                    <ExternalLink className="size-3.5" />
                  </Button>
                }
              />
            )}
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Lines</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={entry.lines} rowKey={(row) => row.id} emptyMessage="No lines." />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col items-end gap-1.5 py-4">
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Total Debit</span>
            <span>{formatCurrency(entry.total_debit)}</span>
          </div>
          <Separator className="w-full max-w-64" />
          <div className="flex w-full max-w-64 justify-between text-base font-semibold">
            <span>Total Credit</span>
            <span>{formatCurrency(entry.total_credit)}</span>
          </div>
        </CardContent>
      </Card>

      {entry.requires_approval && (
        <ApprovalPanel
          approvableType={APPROVABLE_TYPE}
          approvableId={entry.id}
          module="journal_entry"
          documentStatus={entry.status}
          documentLabel={entry.document_number ?? 'this Journal Entry'}
          onChanged={invalidate}
        />
      )}

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(entry.created_at)} />
            <DetailField label="Posted" value={entry.submitted_at ? formatDate(entry.submitted_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={entry.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
