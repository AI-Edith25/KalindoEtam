import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ExternalLink, Loader2, Pencil, Send, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { deletePaymentEntry, fetchPaymentEntry, submitPaymentEntry } from '../api/paymentEntryApi'
import { PAYMENT_METHOD_LABELS } from '../lib/paymentMethodLabels'
import { resolveSourceDocumentLink } from '../lib/sourceDocumentLink'

/** Read-only, section-grouped — same shell as GoodsReceiptDetailPage. */
export function OutgoingPaymentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const paymentQuery = useQuery({
    queryKey: ['payment-entries', id],
    queryFn: () => fetchPaymentEntry(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['payment-entries'] })

  const submitMutation = useMutation({
    mutationFn: () => submitPaymentEntry(id!),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Payment confirmed — payable updated.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deletePaymentEntry(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Payment deleted.')
      navigate('/finance/outgoing')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (paymentQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const payment = paymentQuery.data
  if (!payment) return null

  const line = payment.items[0]

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={payment.document_number ?? 'Outgoing Payment'}
        description="Outgoing payment details."
        actions={
          payment.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/finance/outgoing/${payment.id}/edit`)}>
                <Pencil className="size-4" />
                Edit
              </Button>
              <Button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Payment
              </Button>
              <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                <Trash2 className="size-4" />
                Delete
              </Button>
            </div>
          ) : undefined
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Payment Details</CardTitle>
          <StatusBadge status={payment.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Payment No" value={payment.document_number ?? '—'} />
            <DetailField
              label="Source Document"
              value={
                line ? (
                  <Button
                    variant="link"
                    className="h-auto p-0"
                    onClick={() => navigate(resolveSourceDocumentLink('purchase_order', line.accounts_payable.purchase_order_id))}
                  >
                    View Purchase Order
                    <ExternalLink className="size-3.5" />
                  </Button>
                ) : (
                  '—'
                )
              }
            />
            <DetailField label="Supplier" value={payment.supplier?.supplier_name ?? '—'} />
            <DetailField label="Payment Date" value={formatDate(payment.payment_date)} />
            <DetailField label="Payment Method" value={PAYMENT_METHOD_LABELS[payment.payment_method]} />
            <DetailField label="Amount" value={formatCurrency(payment.total_amount)} />
            <DetailField label="Reference Number" value={payment.reference_number || '—'} />
            <DetailField label="Notes" value={payment.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      {line && (
        <Card>
          <CardHeader>
            <CardTitle>Purchase Summary</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Grand Total</span>
              <span className="text-sm font-medium">{formatCurrency(line.accounts_payable.amount)}</span>
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Paid Amount</span>
              <span className="text-sm font-medium">{formatCurrency(line.accounts_payable.paid_amount)}</span>
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Outstanding</span>
              <span className="text-sm font-medium">{formatCurrency(line.accounts_payable.outstanding_amount)}</span>
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Payment Status</span>
              <StatusBadge status={line.accounts_payable.status} />
            </div>
          </CardContent>
        </Card>
      )}

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={payment.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
