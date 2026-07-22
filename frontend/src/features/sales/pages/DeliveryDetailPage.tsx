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
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { computeTax, lineAmount } from '@/shared/lib/documentTotals'
import { deleteDelivery, fetchDelivery, submitDelivery } from '../api/deliveryApi'
import type { DeliveryItem } from '../types'

const lineColumns: DataTableColumn<DeliveryItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

/** Read-only, section-grouped — same shell as GoodsReceiptDetailPage (DetailField/DetailSection outside a Drawer, DataTable for read-only lines). */
export function DeliveryDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const deliveryQuery = useQuery({
    queryKey: ['deliveries', id],
    queryFn: () => fetchDelivery(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['deliveries'] })

  const submitMutation = useMutation({
    mutationFn: () => submitDelivery(id!),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast.success('Delivery confirmed — stock updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteDelivery(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Delivery deleted.')
      navigate('/sales/deliveries')
    },
    onError: (error) => toastApiError(error),
  })

  if (deliveryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const delivery = deliveryQuery.data
  if (!delivery) return null

  const subtotal = delivery.items.reduce((sum, line) => sum + lineAmount(line), 0)
  const tax = computeTax()

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={delivery.document_number ?? 'Delivery'}
        description="Delivery details."
        actions={
          delivery.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/sales/deliveries/${delivery.id}/edit`)}>
                <Pencil className="size-4" />
                Edit
              </Button>
              <Button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Delivery
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
          <CardTitle>Delivery Details</CardTitle>
          <StatusBadge status={delivery.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={delivery.document_number ?? '—'} />
            <DetailField
              label="Sales Order"
              value={
                <Button
                  variant="link"
                  className="h-auto p-0"
                  onClick={() => navigate(`/sales/orders/${delivery.sales_order_id}`)}
                >
                  View Sales Order
                  <ExternalLink className="size-3.5" />
                </Button>
              }
            />
            <DetailField label="Customer" value={delivery.customer?.customer_name ?? '—'} />
            <DetailField label="Warehouse" value={delivery.warehouse?.name ?? '—'} />
            <DetailField label="Delivery Date" value={formatDate(delivery.delivery_date)} />
            <DetailField label="Due Date" value={formatDate(delivery.due_date)} />
            <DetailField label="Notes" value={delivery.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Delivered Items</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={delivery.items} rowKey={(row) => row.id} emptyMessage="No line items." />
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
            <DetailField label="Created" value={formatDate(delivery.created_at)} />
            <DetailField label="Submitted" value={delivery.submitted_at ? formatDate(delivery.submitted_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={delivery.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
