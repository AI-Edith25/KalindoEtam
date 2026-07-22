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
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { computeTax } from '@/shared/lib/documentTotals'
import { cancelSalesOrder, deleteSalesOrder, fetchSalesOrder, submitSalesOrder } from '../api/salesOrderApi'
import { fetchDeliveries } from '../api/deliveryApi'
import { DeliveryProgress } from '../components/DeliveryProgress'
import { computeDeliveryStatus } from '../lib/deliveryProgress'
import { ApprovalPanel } from '@/features/approval/components/ApprovalPanel'
import type { Delivery, SalesOrderItem } from '../types'

const APPROVABLE_TYPE = 'App\\Models\\SalesOrder'

const lineColumns: DataTableColumn<SalesOrderItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code ?? '—' },
  { header: 'Item Name', accessor: (row) => row.item_name ?? '—' },
  { header: 'Ordered Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
  { header: 'Delivered Qty', accessor: (row) => formatNumber(row.delivered_qty), className: 'text-right' },
  { header: 'Remaining Qty', accessor: (row) => formatNumber(row.outstanding_qty), className: 'text-right' },
]

function deliveryQty(delivery: Delivery): number {
  return delivery.items.reduce((sum, line) => sum + line.qty, 0)
}

const deliveryColumns = (onNavigate: (id: string) => void): DataTableColumn<Delivery>[] => [
  {
    header: 'Document Number',
    accessor: (row) => (
      <Button variant="link" className="h-auto p-0" onClick={() => onNavigate(row.id)}>
        {row.document_number ?? '—'}
        <ExternalLink className="size-3.5" />
      </Button>
    ),
  },
  { header: 'Delivery Date', accessor: (row) => formatDate(row.delivery_date) },
  { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
  { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  { header: 'Qty Delivered', accessor: (row) => formatNumber(deliveryQty(row)), className: 'text-right' },
]

/** Read-only, section-grouped — same shell as PurchaseOrderDetailPage. */
export function SalesOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const orderQuery = useQuery({
    queryKey: ['sales-orders', id],
    queryFn: () => fetchSalesOrder(id!),
  })

  // Only a submitted SO can ever have Deliveries against it (draft has none yet; cancel() is blocked once any exist) — no point fetching otherwise.
  const relatedDeliveriesQuery = useQuery({
    queryKey: ['deliveries-for-sales-order', id],
    queryFn: () => fetchDeliveries({ page: 1, per_page: 100 }),
    enabled: orderQuery.data?.status === 'submitted',
  })
  const relatedDeliveries = (relatedDeliveriesQuery.data?.data ?? []).filter((delivery) => delivery.sales_order_id === id)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales-orders'] })

  const submitMutation = useMutation({
    mutationFn: () => submitSalesOrder(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Sales Order submitted.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelSalesOrder(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Sales Order cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteSalesOrder(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Sales Order deleted.')
      navigate('/sales/orders')
    },
    onError: (error) => toastApiError(error),
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
  const tax = computeTax()
  const blockedByApproval = order.requires_approval && order.latest_approval?.status !== 'approved'

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={order.document_number ?? 'Sales Order'}
        description="Sales order details."
        actions={
          <div className="flex items-center gap-2">
            {order.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/sales/orders/${order.id}/edit`)}>
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

      {computeDeliveryStatus(order) && (
        <Card>
          <CardHeader>
            <CardTitle>Delivery Progress</CardTitle>
          </CardHeader>
          <CardContent>
            <DeliveryProgress order={order} size="lg" />
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
            <DetailField label="Customer" value={order.customer?.customer_name ?? '—'} />
            <DetailField label="Order Date" value={formatDate(order.order_date)} />
            <DetailField label="Expected Delivery Date" value={formatDate(order.expected_delivery_date)} />
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
            <CardTitle>Deliveries</CardTitle>
          </CardHeader>
          <CardContent>
            <DataTable
              columns={deliveryColumns((deliveryId) => navigate(`/sales/deliveries/${deliveryId}`))}
              data={relatedDeliveries}
              rowKey={(row) => row.id}
              isLoading={relatedDeliveriesQuery.isLoading}
              emptyMessage="No goods have been delivered against this order yet."
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
            <span>{formatCurrency(subtotal + tax)}</span>
          </div>
        </CardContent>
      </Card>

      {order.requires_approval && (
        <ApprovalPanel
          approvableType={APPROVABLE_TYPE}
          approvableId={order.id}
          module="sales_order"
          documentStatus={order.status}
          documentLabel={order.document_number ?? 'this Sales Order'}
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
