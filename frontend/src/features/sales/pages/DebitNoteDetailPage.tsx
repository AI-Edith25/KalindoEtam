import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ExternalLink, Loader2, Pencil, RotateCcw, Send, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { deleteDebitNote, fetchDebitNote, reverseDebitNote, submitDebitNote } from '../api/debitNoteApi'
import { DEBIT_NOTE_REASON_LABELS } from '../lib/debitNoteReasonLabels'
import type { DebitNoteItem } from '../types'

const lineColumns: DataTableColumn<DebitNoteItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code ?? '—' },
  { header: 'Description', accessor: (row) => row.description },
  { header: 'Qty Adjusted', accessor: (row) => (row.qty_adjusted ? formatNumber(row.qty_adjusted) : '—'), className: 'text-right' },
  { header: 'Rate', accessor: (row) => (row.rate ? formatCurrency(row.rate) : '—'), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

export function DebitNoteDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const debitNoteQuery = useQuery({
    queryKey: ['debit-notes', id],
    queryFn: () => fetchDebitNote(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['debit-notes'] })
    queryClient.invalidateQueries({ queryKey: ['invoices'] })
    queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitDebitNote(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note submitted — Accounts Receivable updated.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const reverseMutation = useMutation({
    mutationFn: () => reverseDebitNote(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note reversed.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteDebitNote(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note deleted.')
      navigate('/sales/debit-notes')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (debitNoteQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const debitNote = debitNoteQuery.data
  if (!debitNote) return null

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={debitNote.document_number ?? 'Debit Note'}
        description="Debit Note details."
        actions={
          <div className="flex items-center gap-2">
            {debitNote.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/sales/debit-notes/${debitNote.id}/edit`)}>
                  <Pencil className="size-4" />
                  Edit
                </Button>
                <Button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                  {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                  Submit
                </Button>
                <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                  <Trash2 className="size-4" />
                  Delete
                </Button>
              </>
            )}
            {debitNote.status === 'submitted' && !debitNote.is_reversed && (
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
          <CardTitle>Debit Note Information</CardTitle>
          <div className="flex items-center gap-2">
            <StatusBadge status={debitNote.status} />
            {debitNote.is_reversed && <Badge variant="secondary">Reversed</Badge>}
          </div>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Debit Note Number" value={debitNote.document_number ?? '—'} />
            <DetailField label="Debit Note Date" value={formatDate(debitNote.debit_note_date)} />
            <DetailField label="Reason" value={DEBIT_NOTE_REASON_LABELS[debitNote.reason]} />
            <DetailField label="Customer" value={debitNote.customer?.customer_name ?? '—'} />
            <DetailField
              label="Invoice"
              value={
                debitNote.invoice ? (
                  <Button variant="link" className="h-auto p-0" onClick={() => navigate(`/sales/invoices/${debitNote.invoice_id}`)}>
                    {debitNote.invoice.document_number ?? '—'}
                    <ExternalLink className="size-3.5" />
                  </Button>
                ) : (
                  '—'
                )
              }
            />
            <DetailField label="Notes" value={debitNote.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Adjustments</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={debitNote.items} rowKey={(row) => row.id} emptyMessage="No lines — header-only adjustment." />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col items-end gap-1.5 py-4">
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Subtotal (Goods)</span>
            <span>{formatCurrency(debitNote.subtotal_goods)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Subtotal (Other)</span>
            <span>{formatCurrency(debitNote.subtotal_other)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Additional Tax</span>
            <span>{formatCurrency(debitNote.tax_amount)}</span>
          </div>
          <Separator className="w-full max-w-64" />
          <div className="flex w-full max-w-64 justify-between text-base font-semibold">
            <span>Total</span>
            <span>{formatCurrency(debitNote.total_amount)}</span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>History</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(debitNote.created_at)} />
            <DetailField label="Submitted" value={debitNote.submitted_at ? formatDate(debitNote.submitted_at) : '—'} />
            <DetailField label="Reversed" value={debitNote.reversed_at ? formatDate(debitNote.reversed_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={debitNote.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
