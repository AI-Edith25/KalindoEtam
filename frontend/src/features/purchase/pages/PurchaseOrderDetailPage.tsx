import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, ExternalLink, Loader2, Pencil, Send, Trash2 } from 'lucide-react'
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
import { cancelPurchaseOrder, deletePurchaseOrder, fetchPurchaseOrder, submitPurchaseOrder } from '../api/purchaseOrderApi'
import { fetchGoodsReceipts } from '../api/goodsReceiptApi'
import { ReceivingProgress } from '../components/ReceivingProgress'
import { computeReceivingStatus } from '../lib/receivingProgress'
import { ApprovalPanel } from '@/features/approval/components/ApprovalPanel'
import type { GoodsReceipt, PurchaseOrderItem } from '../types'

const APPROVABLE_TYPE = 'App\\Models\\PurchaseOrder'

const lineColumns: DataTableColumn<PurchaseOrderItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code ?? '—' },
  { header: 'Item Name', accessor: (row) => row.item_name ?? '—' },
  { header: 'Ordered Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
  { header: 'Received Qty', accessor: (row) => formatNumber(row.received_qty), className: 'text-right' },
  { header: 'Remaining Qty', accessor: (row) => formatNumber(row.outstanding_qty), className: 'text-right' },
]

function goodsReceiptQty(receipt: GoodsReceipt): number {
  return receipt.items.reduce((sum, line) => sum + line.qty, 0)
}

const goodsReceiptColumns = (onNavigate: (id: string) => void): DataTableColumn<GoodsReceipt>[] => [
  {
    header: 'Document Number',
    accessor: (row) => (
      <Button variant="link" className="h-auto p-0" onClick={() => onNavigate(row.id)}>
        {row.document_number ?? '—'}
        <ExternalLink className="size-3.5" />
      </Button>
    ),
  },
  { header: 'Receipt Date', accessor: (row) => formatDate(row.receipt_date) },
  { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
  { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  { header: 'Qty Received', accessor: (row) => formatNumber(goodsReceiptQty(row)), className: 'text-right' },
]

/** Read-only, section-grouped — reuses DetailDrawerLayout's DetailField/DetailSection outside a Drawer, and DataTable for the (non-editable) line items. */
export function PurchaseOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const orderQuery = useQuery({
    queryKey: ['purchase-orders', id],
    queryFn: () => fetchPurchaseOrder(id!),
  })

  // Only a submitted PO can ever have Goods Receipts against it (draft has none yet; cancel() is blocked once any exist) — no point fetching otherwise.
  const relatedReceiptsQuery = useQuery({
    queryKey: ['goods-receipts-for-purchase-order', id],
    queryFn: () => fetchGoodsReceipts({ page: 1, per_page: 100 }),
    enabled: orderQuery.data?.status === 'submitted',
  })
  const relatedReceipts = (relatedReceiptsQuery.data?.data ?? []).filter((gr) => gr.purchase_order_id === id)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseOrder(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Order submitted.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelPurchaseOrder(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Order cancelled.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deletePurchaseOrder(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Order deleted.')
      navigate('/purchase/orders')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  if (orderQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const order = orderQuery.data
  if (!order) return null

  const subtotal = Number(order.total_amount)
  const tax = Number(order.tax_amount)
  const grandTotal = Number(order.grand_total)
  // Submit is gated the same way the backend's own submit() guard reads it — approval must be
  // APPROVED (or not required at all) before Submit can succeed. See docs/APPROVAL_WORKFLOW_DESIGN.md §2.
  const blockedByApproval = order.requires_approval && order.latest_approval?.status !== 'approved'

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={order.document_number ?? 'Purchase Order'}
        description="Purchase order details."
        actions={
          <div className="flex items-center gap-2">
            {order.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/purchase/orders/${order.id}/edit`)}>
                  <Pencil className="size-4" />
                  Edit
                </Button>
                <Button
                  onClick={() => submitMutation.mutate()}
                  disabled={submitMutation.isPending || blockedByApproval}
                  title={blockedByApproval ? 'This order needs an approved request before it can be submitted.' : undefined}
                >
                  {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                  Submit
                </Button>
                <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                  <Trash2 className="size-4" />
                  Delete
                </Button>
              </>
            )}
            {order.status === 'submitted' && (
              <Button variant="destructive" onClick={() => cancelMutation.mutate()} disabled={cancelMutation.isPending}>
                {cancelMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Ban className="size-4" />}
                Cancel
              </Button>
            )}
          </div>
        }
      />

      {computeReceivingStatus(order) && (
        <Card>
          <CardHeader>
            <CardTitle>Receiving Progress</CardTitle>
          </CardHeader>
          <CardContent>
            <ReceivingProgress order={order} size="lg" />
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Order Details</CardTitle>
          <StatusBadge status={order.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={order.document_number ?? '—'} />
            <DetailField label="Supplier" value={order.supplier?.supplier_name ?? '—'} />
            <DetailField label="Order Date" value={formatDate(order.order_date)} />
            <DetailField label="Expected Delivery Date" value={formatDate(order.expected_delivery_date)} />
            <DetailField label="Tax" value={order.tax ? `${order.tax.name} (${order.tax.code})` : '—'} />
            <DetailField label="Notes" value={order.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={order.items} rowKey={(row) => row.id} emptyMessage="No line items." />
        </CardContent>
      </Card>

      {order.status === 'submitted' && (
        <Card>
          <CardHeader>
            <CardTitle>Goods Receipts</CardTitle>
          </CardHeader>
          <CardContent>
            <DataTable
              columns={goodsReceiptColumns((receiptId) => navigate(`/purchase/goods-receipts/${receiptId}`))}
              data={relatedReceipts}
              rowKey={(row) => row.id}
              isLoading={relatedReceiptsQuery.isLoading}
              emptyMessage="No goods have been received against this order yet."
            />
          </CardContent>
        </Card>
      )}

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
            <span>{formatCurrency(grandTotal)}</span>
          </div>
        </CardContent>
      </Card>

      {order.requires_approval && (
        <ApprovalPanel
          approvableType={APPROVABLE_TYPE}
          approvableId={order.id}
          module="purchase_order"
          documentStatus={order.status}
          documentLabel={order.document_number ?? 'this Purchase Order'}
          onChanged={invalidate}
        />
      )}

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(order.created_at)} />
            <DetailField label="Submitted" value={order.submitted_at ? formatDate(order.submitted_at) : '—'} />
            <DetailField label="Cancelled" value={order.cancelled_at ? formatDate(order.cancelled_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={order.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
