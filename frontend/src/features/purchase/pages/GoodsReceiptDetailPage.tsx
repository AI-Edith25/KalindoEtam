import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ExternalLink, Loader2, Pencil, Send, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { deleteGoodsReceipt, fetchGoodsReceipt, submitGoodsReceipt } from '../api/goodsReceiptApi'
import { computeTax, lineAmount } from '@/shared/lib/documentTotals'
import type { GoodsReceiptItem } from '../types'

const lineColumns: DataTableColumn<GoodsReceiptItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

/** Read-only, section-grouped — same shell as PurchaseOrderDetailPage (DetailField/DetailSection outside a Drawer, DataTable for read-only lines). */
export function GoodsReceiptDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const receiptQuery = useQuery({
    queryKey: ['goods-receipts', id],
    queryFn: () => fetchGoodsReceipt(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['goods-receipts'] })

  const submitMutation = useMutation({
    mutationFn: () => submitGoodsReceipt(id!),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      toast.success('Receipt confirmed — stock updated.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteGoodsReceipt(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Goods Receipt deleted.')
      navigate('/purchase/goods-receipts')
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

  const subtotal = receipt.items.reduce((sum, line) => sum + lineAmount(line), 0)
  const tax = computeTax()

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={receipt.document_number ?? 'Goods Receipt'}
        description="Goods receipt details."
        actions={
          receipt.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/purchase/goods-receipts/${receipt.id}/edit`)}>
                <Pencil className="size-4" />
                Edit
              </Button>
              <Button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Receipt
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
          <CardTitle>Receipt Details</CardTitle>
          <StatusBadge status={receipt.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={receipt.document_number ?? '—'} />
            <DetailField
              label="Purchase Order"
              value={
                <Button
                  variant="link"
                  className="h-auto p-0"
                  onClick={() => navigate(`/purchase/orders/${receipt.purchase_order_id}`)}
                >
                  View Purchase Order
                  <ExternalLink className="size-3.5" />
                </Button>
              }
            />
            <DetailField label="Supplier" value={receipt.supplier?.supplier_name ?? '—'} />
            <DetailField label="Warehouse" value={receipt.warehouse?.name ?? '—'} />
            <DetailField label="Receipt Date" value={formatDate(receipt.receipt_date)} />
            <DetailField label="Due Date" value={formatDate(receipt.due_date)} />
            <DetailField label="Notes" value={receipt.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Received Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={receipt.items} rowKey={(row) => row.id} emptyMessage="No line items." />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col items-end gap-1.5 py-4">
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Subtotal</span>
            <span>{formatCurrency(subtotal)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Tax</span>
            <span>{formatCurrency(tax)}</span>
          </div>
          <Separator className="w-full max-w-64" />
          <div className="flex w-full max-w-64 justify-between text-base font-semibold">
            <span>Grand Total</span>
            <span>{formatCurrency(subtotal + tax)}</span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(receipt.created_at)} />
            <DetailField label="Submitted" value={receipt.submitted_at ? formatDate(receipt.submitted_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={receipt.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
