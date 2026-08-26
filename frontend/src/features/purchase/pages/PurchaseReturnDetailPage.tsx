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
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { deletePurchaseReturn, fetchPurchaseReturn, reversePurchaseReturn, submitPurchaseReturn } from '../api/purchaseReturnApi'
import { PURCHASE_RETURN_REASON_LABELS } from '../lib/purchaseReturnReasonLabels'
import type { PurchaseReturnItem } from '../types'

const lineColumns: DataTableColumn<PurchaseReturnItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty Returned', accessor: (row) => formatNumber(row.qty_returned), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

export function PurchaseReturnDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const purchaseReturnQuery = useQuery({
    queryKey: ['purchase-returns', id],
    queryFn: () => fetchPurchaseReturn(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['purchase-returns'] })
    queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
    queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseReturn(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Return submitted — Accounts Payable updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const reverseMutation = useMutation({
    mutationFn: () => reversePurchaseReturn(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Return reversed.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deletePurchaseReturn(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Return deleted.')
      navigate('/purchase/returns')
    },
    onError: (error) => toastApiError(error),
  })

  if (purchaseReturnQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const purchaseReturn = purchaseReturnQuery.data
  if (!purchaseReturn) return null

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={purchaseReturn.document_number ?? 'Purchase Return'}
        description="Purchase Return details."
        actions={
          <div className="flex items-center gap-2">
            {purchaseReturn.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/purchase/returns/${purchaseReturn.id}/edit`)}>
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
            {purchaseReturn.status === 'submitted' && !purchaseReturn.is_reversed && (
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
          <CardTitle>Return Information</CardTitle>
          <div className="flex items-center gap-2">
            <StatusBadge status={purchaseReturn.status} />
            {purchaseReturn.is_reversed && <Badge variant="secondary">Reversed</Badge>}
          </div>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Return Number" value={purchaseReturn.document_number ?? '—'} />
            <DetailField label="Return Date" value={formatDate(purchaseReturn.return_date)} />
            <DetailField label="Reason" value={PURCHASE_RETURN_REASON_LABELS[purchaseReturn.reason]} />
            <DetailField label="Supplier" value={purchaseReturn.supplier?.supplier_name ?? '—'} />
            <DetailField
              label="Purchase Invoice"
              value={
                purchaseReturn.purchase_invoice ? (
                  <Button variant="link" className="h-auto p-0" onClick={() => navigate(`/purchase/invoices/${purchaseReturn.purchase_invoice_id}`)}>
                    {purchaseReturn.purchase_invoice.document_number ?? '—'}
                    <ExternalLink className="size-3.5" />
                  </Button>
                ) : (
                  '—'
                )
              }
            />
            <DetailField label="Notes" value={purchaseReturn.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Selection</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={purchaseReturn.items} rowKey={(row) => row.id} emptyMessage="No lines — header-only adjustment." />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col items-end gap-1.5 py-4">
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Subtotal</span>
            <span>{formatCurrency(purchaseReturn.subtotal)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Tax Reversed</span>
            <span>{formatCurrency(purchaseReturn.tax_amount)}</span>
          </div>
          <Separator className="w-full max-w-64" />
          <div className="flex w-full max-w-64 justify-between text-base font-semibold">
            <span>Total</span>
            <span>{formatCurrency(purchaseReturn.total_amount)}</span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>History</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(purchaseReturn.created_at)} />
            <DetailField label="Submitted" value={purchaseReturn.submitted_at ? formatDate(purchaseReturn.submitted_at) : '—'} />
            <DetailField label="Reversed" value={purchaseReturn.reversed_at ? formatDate(purchaseReturn.reversed_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={purchaseReturn.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
