import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ExternalLink, Loader2, Pencil, RotateCcw, Send, SplitSquareHorizontal, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { deleteReceiptEntry, fetchReceiptEntry, submitReceiptEntry } from '../api/receiptEntryApi'
import { reverseAllocation } from '../api/paymentAllocationApi'
import { PaymentAllocationDrawer } from '../components/PaymentAllocationDrawer'
import { PAYMENT_METHOD_LABELS } from '../lib/paymentMethodLabels'
import { resolveSourceDocumentLink } from '../lib/sourceDocumentLink'

/** Read-only, section-grouped — mirrors OutgoingPaymentDetailPage exactly. */
export function IncomingPaymentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [allocating, setAllocating] = useState(false)

  const receiptQuery = useQuery({
    queryKey: ['receipt-entries', id],
    queryFn: () => fetchReceiptEntry(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['receipt-entries'] })

  const submitMutation = useMutation({
    mutationFn: () => submitReceiptEntry(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Payment received. Allocate it to an invoice below.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteReceiptEntry(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Payment deleted.')
      navigate('/finance/incoming')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const reverseMutation = useMutation({
    mutationFn: (allocationId: string) => reverseAllocation(allocationId),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Allocation reversed.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (receiptQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const receipt = receiptQuery.data
  if (!receipt) return null

  const unallocated = Number(receipt.unallocated_amount)

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={receipt.document_number ?? 'Incoming Payment'}
        description="Incoming payment details."
        actions={
          receipt.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/finance/incoming/${receipt.id}/edit`)}>
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
          ) : unallocated > 0 ? (
            <Button onClick={() => setAllocating(true)}>
              <SplitSquareHorizontal className="size-4" />
              Allocate Payment
            </Button>
          ) : undefined
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Payment Details</CardTitle>
          <StatusBadge status={receipt.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Payment No" value={receipt.document_number ?? '—'} />
            <DetailField label="Customer" value={receipt.customer?.customer_name ?? '—'} />
            <DetailField label="Payment Date" value={formatDate(receipt.receipt_date)} />
            <DetailField label="Payment Method" value={PAYMENT_METHOD_LABELS[receipt.payment_method]} />
            <DetailField label="Reference Number" value={receipt.reference_number || '—'} />
            <DetailField label="Notes" value={receipt.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      {receipt.status === 'submitted' && (
        <Card>
          <CardHeader>
            <CardTitle>Allocation Summary</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Amount Received</span>
              <span className="text-sm font-medium">{formatCurrency(receipt.total_amount)}</span>
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Allocated</span>
              <span className="text-sm font-medium">{formatCurrency(receipt.allocated_amount)}</span>
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Unallocated</span>
              <span className={unallocated > 0 ? 'text-sm font-medium text-amber-600' : 'text-sm font-medium'}>
                {formatCurrency(unallocated)}
              </span>
            </div>
          </CardContent>
        </Card>
      )}

      {receipt.items.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Allocations</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {receipt.items.map((item) => (
              <div key={item.id} className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-1">
                  <div className="flex items-center gap-2">
                    {item.accounts_receivable.invoice ? (
                      <Button
                        variant="link"
                        className="h-auto p-0"
                        onClick={() => navigate(resolveSourceDocumentLink('invoice', item.accounts_receivable.invoice!.id))}
                      >
                        {item.accounts_receivable.invoice.document_number ?? '—'}
                        <ExternalLink className="size-3.5" />
                      </Button>
                    ) : (
                      <span className="text-sm font-medium">{item.accounts_receivable.reference_number}</span>
                    )}
                    {item.is_reversed && <Badge variant="secondary">Reversed</Badge>}
                  </div>
                  <span className="text-xs text-muted-foreground">
                    {formatCurrency(item.received_amount)} · {formatDate(item.allocation_date)}
                  </span>
                </div>
                {!item.is_reversed && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => reverseMutation.mutate(item.id)}
                    disabled={reverseMutation.isPending}
                  >
                    <RotateCcw className="size-3.5" />
                    Reverse
                  </Button>
                )}
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={receipt.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />

      {receipt.status === 'submitted' && (
        <PaymentAllocationDrawer open={allocating} onOpenChange={setAllocating} receiptEntry={receipt} />
      )}
    </div>
  )
}
