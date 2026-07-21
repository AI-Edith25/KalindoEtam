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
import { deleteCreditNote, fetchCreditNote, reverseCreditNote, submitCreditNote } from '../api/creditNoteApi'
import { CREDIT_NOTE_REASON_LABELS } from '../lib/creditNoteReasonLabels'
import type { CreditNoteItem } from '../types'

const lineColumns: DataTableColumn<CreditNoteItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty Credited', accessor: (row) => formatNumber(row.qty_credited), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
  { header: 'Inventory Impact', accessor: (row) => row.inventory_impact ?? '—' },
]

export function CreditNoteDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const creditNoteQuery = useQuery({
    queryKey: ['credit-notes', id],
    queryFn: () => fetchCreditNote(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
    queryClient.invalidateQueries({ queryKey: ['invoices'] })
    queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitCreditNote(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Credit Note submitted — Accounts Receivable updated.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const reverseMutation = useMutation({
    mutationFn: () => reverseCreditNote(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Credit Note reversed.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteCreditNote(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Credit Note deleted.')
      navigate('/sales/credit-notes')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (creditNoteQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const creditNote = creditNoteQuery.data
  if (!creditNote) return null

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={creditNote.document_number ?? 'Credit Note'}
        description="Credit Note details."
        actions={
          <div className="flex items-center gap-2">
            {creditNote.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/sales/credit-notes/${creditNote.id}/edit`)}>
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
            {creditNote.status === 'submitted' && !creditNote.is_reversed && (
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
          <CardTitle>Credit Note Information</CardTitle>
          <div className="flex items-center gap-2">
            <StatusBadge status={creditNote.status} />
            {creditNote.is_reversed && <Badge variant="secondary">Reversed</Badge>}
          </div>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Credit Note Number" value={creditNote.document_number ?? '—'} />
            <DetailField label="Credit Note Date" value={formatDate(creditNote.credit_note_date)} />
            <DetailField label="Reason" value={CREDIT_NOTE_REASON_LABELS[creditNote.reason]} />
            <DetailField label="Customer" value={creditNote.customer?.customer_name ?? '—'} />
            <DetailField
              label="Invoice"
              value={
                creditNote.invoice ? (
                  <Button variant="link" className="h-auto p-0" onClick={() => navigate(`/sales/invoices/${creditNote.invoice_id}`)}>
                    {creditNote.invoice.document_number ?? '—'}
                    <ExternalLink className="size-3.5" />
                  </Button>
                ) : (
                  '—'
                )
              }
            />
            <DetailField label="Notes" value={creditNote.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Selection</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={creditNote.items} rowKey={(row) => row.id} emptyMessage="No lines — header-only adjustment." />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col items-end gap-1.5 py-4">
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Subtotal</span>
            <span>{formatCurrency(creditNote.subtotal)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Discount Reversed</span>
            <span>-{formatCurrency(creditNote.discount_amount)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Tax Reversed</span>
            <span>{formatCurrency(creditNote.tax_amount)}</span>
          </div>
          <Separator className="w-full max-w-64" />
          <div className="flex w-full max-w-64 justify-between text-base font-semibold">
            <span>Total</span>
            <span>{formatCurrency(creditNote.total_amount)}</span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>History</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(creditNote.created_at)} />
            <DetailField label="Submitted" value={creditNote.submitted_at ? formatDate(creditNote.submitted_at) : '—'} />
            <DetailField label="Reversed" value={creditNote.reversed_at ? formatDate(creditNote.reversed_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={creditNote.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
