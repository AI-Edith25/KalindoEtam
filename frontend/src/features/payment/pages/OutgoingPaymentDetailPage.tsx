import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ExternalLink, Loader2, Pencil, Printer, RotateCcw, Send, SplitSquareHorizontal, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { deletePaymentEntry, fetchPaymentEntry, submitPaymentEntry } from '../api/paymentEntryApi'
import { reversePaymentEntryAllocation } from '../api/paymentEntryAllocationApi'
import { PaymentEntryAllocationDrawer } from '../components/PaymentEntryAllocationDrawer'
import { resolveSourceDocumentLink } from '../lib/sourceDocumentLink'

/** Read-only, section-grouped — same shell as GoodsReceiptDetailPage, and mirrors IncomingPaymentDetailPage's Allocation Summary/Allocations/Allocate flow for the payable side. */
export function OutgoingPaymentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [allocating, setAllocating] = useState(false)

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
      toast.success('Payment confirmed. Allocate it to a bill below.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deletePaymentEntry(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Payment deleted.')
      navigate('/finance/outgoing')
    },
    onError: (error) => toastApiError(error),
  })

  const reverseMutation = useMutation({
    mutationFn: (allocationId: string) => reversePaymentEntryAllocation(allocationId),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Allocation reversed.')
    },
    onError: (error) => toastApiError(error),
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

  const unallocated = Number(payment.unallocated_amount)

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={payment.document_number ?? 'Payment Voucher'}
        description="Payment voucher details."
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
          ) : (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/finance/outgoing/${payment.id}/print`)}>
                <Printer className="size-4" />
                Print
              </Button>
              {payment.payment_type === 'supplier' && unallocated > 0 && (
                <Button onClick={() => setAllocating(true)}>
                  <SplitSquareHorizontal className="size-4" />
                  Allocate Payment
                </Button>
              )}
            </div>
          )
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
            <DetailField label="Type" value={<StatusBadge status={payment.payment_type} />} />
            {payment.payment_type === 'general_expense' ? (
              <>
                <DetailField label="Category" value={payment.expense_account?.name ?? '—'} />
                <DetailField label="Description" value={payment.description || '—'} />
              </>
            ) : (
              <DetailField label="Supplier" value={payment.supplier?.supplier_name ?? '—'} />
            )}
            <DetailField label="Payment Date" value={formatDate(payment.payment_date)} />
            <DetailField label="Payment Method" value={payment.cash_account?.name ?? '—'} />
            <DetailField label="Amount" value={formatCurrency(payment.total_amount)} />
            <DetailField label="Reference Number" value={payment.reference_number || '—'} />
            <DetailField label="Notes" value={payment.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      {payment.payment_type === 'supplier' && payment.status === 'submitted' && (
        <Card>
          <CardHeader>
            <CardTitle>Allocation Summary</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Amount Paid</span>
              <span className="text-sm font-medium">{formatCurrency(payment.total_amount)}</span>
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-xs text-muted-foreground">Allocated</span>
              <span className="text-sm font-medium">{formatCurrency(payment.allocated_amount)}</span>
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

      {payment.items.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Allocations</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {payment.items.map((item) => (
              <div key={item.id} className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-1">
                  <div className="flex items-center gap-2">
                    <Button
                      variant="link"
                      className="h-auto p-0"
                      onClick={() =>
                        navigate(
                          // Pre-Purchase-Invoice payables (created straight from a Goods Receipt,
                          // before 65bc42a) have no invoice_id — fall back to their Goods Receipt.
                          item.accounts_payable.invoice_id
                            ? resolveSourceDocumentLink('purchase_invoice', item.accounts_payable.invoice_id)
                            : resolveSourceDocumentLink('goods_receipt', item.accounts_payable.goods_receipt_id),
                        )
                      }
                    >
                      {item.accounts_payable.reference_number}
                      <ExternalLink className="size-3.5" />
                    </Button>
                    {item.is_reversed && <Badge variant="secondary">Reversed</Badge>}
                  </div>
                  <span className="text-xs text-muted-foreground">
                    {formatCurrency(item.allocated_amount)} · {formatDate(item.allocation_date)}
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
        itemLabel={payment.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />

      {payment.payment_type === 'supplier' && payment.status === 'submitted' && (
        <PaymentEntryAllocationDrawer open={allocating} onOpenChange={setAllocating} paymentEntry={payment} />
      )}
    </div>
  )
}
