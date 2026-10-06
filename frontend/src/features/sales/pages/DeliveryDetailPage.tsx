import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, ExternalLink, Loader2, Pencil, Printer, Send, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { ConfirmationDialog } from '@/components/shared/ConfirmationDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { lineAmount } from '@/shared/lib/documentTotals'
import { openPrintWindow } from '@/shared/lib/printOptions'
import { cancelDelivery, completeDelivery, deleteDelivery, fetchDelivery } from '../api/deliveryApi'
import type { DeliveryItem } from '../types'

const lineColumns: DataTableColumn<DeliveryItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Discount', accessor: (row) => (Number(row.discount_amount) > 0 ? `-${formatCurrency(row.discount_amount)}` : '—'), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.net_amount), className: 'text-right' },
  { header: 'Tax', accessor: (row) => formatCurrency(row.tax_amount), className: 'text-right' },
]

/** Read-only, section-grouped — same shell as GoodsReceiptDetailPage (DetailField/DetailSection outside a Drawer, DataTable for read-only lines). */
export function DeliveryDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [confirmingCancel, setConfirmingCancel] = useState(false)
  const canEditComplete = useHasPermission('sales.deliveries.edit')

  const deliveryQuery = useQuery({
    queryKey: ['deliveries', id],
    queryFn: () => fetchDelivery(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['deliveries'] })

  const completeMutation = useMutation({
    mutationFn: () => completeDelivery(id!),
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

  const cancelMutation = useMutation({
    mutationFn: () => cancelDelivery(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Delivery cancelled.')
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
  const discount = Number(delivery.discount_amount) || 0
  const tax = Number(delivery.tax_amount) || 0

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={delivery.document_number ?? 'Delivery'}
        description="Delivery details."
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => openPrintWindow(`/sales/deliveries/${delivery.id}/print`)}>
              <Printer className="size-4" />
              Print
            </Button>
            {delivery.status === 'pending' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/sales/deliveries/${delivery.id}/edit`)}>
                  <Pencil className="size-4" />
                  Edit
                </Button>
                <Button onClick={() => completeMutation.mutate()} disabled={completeMutation.isPending}>
                  {completeMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                  Confirm Delivery
                </Button>
                <Button variant="outline" onClick={() => setConfirmingCancel(true)} disabled={cancelMutation.isPending}>
                  <Ban className="size-4" />
                  Cancel
                </Button>
                <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                  <Trash2 className="size-4" />
                  Delete
                </Button>
              </>
            )}
            {delivery.status === 'complete' && canEditComplete && (
              <Button variant="outline" onClick={() => navigate(`/sales/deliveries/${delivery.id}/edit`)}>
                <Pencil className="size-4" />
                Edit
              </Button>
            )}
            {delivery.status === 'complete' && canEditComplete && !delivery.is_invoiced && (
              <Button variant="outline" onClick={() => setConfirmingCancel(true)} disabled={cancelMutation.isPending}>
                <Ban className="size-4" />
                Cancel
              </Button>
            )}
          </div>
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
              label={`Sales Order${delivery.sales_orders.length > 1 ? 's' : ''}`}
              value={
                delivery.sales_orders.length > 0 ? (
                  <div className="flex flex-wrap gap-x-3 gap-y-1">
                    {delivery.sales_orders.map((so) => (
                      <Button key={so.id} variant="link" className="h-auto p-0" onClick={() => navigate(`/sales/orders/${so.id}`)}>
                        {so.document_number ?? 'View Sales Order'}
                        <ExternalLink className="size-3.5" />
                      </Button>
                    ))}
                  </div>
                ) : (
                  'Direct Delivery (no Sales Order)'
                )
              }
            />
            <DetailField label="Customer" value={delivery.customer?.customer_name ?? '—'} />
            <DetailField label="Customer Code" value={delivery.customer?.customer_code ?? '—'} />
            <DetailField label="Location" value={delivery.warehouse?.name ?? '—'} />
            <DetailField label="Sales Person" value={delivery.sales_order?.sales_person?.name || '—'} />
            <DetailField label="Delivery Date" value={formatDate(delivery.delivery_date)} />
            <DetailField label="Due Date" value={formatDate(delivery.due_date)} />
            <DetailField label="Terms of Payment" value={delivery.terms_of_payment ? `${delivery.terms_of_payment.name} (${delivery.terms_of_payment.code})` : '—'} />
            <DetailField label="Tax" value={delivery.tax ? `${delivery.tax.name} (${delivery.tax.code})` : '—'} />
            <DetailField label="Attn" value={delivery.sales_order?.attention || '—'} />
            <DetailField label="Tel" value={delivery.sales_order?.tel || '—'} />
            <DetailField label="Fax" value={delivery.sales_order?.fax || '—'} />
            <DetailField label="Fleet" value={delivery.fleet || '—'} />
            <DetailField label="Driver" value={delivery.driver || '—'} />
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
          {discount > 0 && (
            <>
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">Total Discount</span>
                <span>-{formatCurrency(discount)}</span>
              </div>
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">DPP</span>
                <span>{formatCurrency(subtotal - discount)}</span>
              </div>
            </>
          )}
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Tax</span>
            <span>{formatCurrency(tax)}</span>
          </div>
          <Separator className="w-full max-w-64" />
          <div className="flex w-full max-w-64 justify-between text-base font-semibold">
            <span>Grand Total</span>
            <span>{formatCurrency(subtotal - discount + tax)}</span>
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
            {delivery.updater && (
              <DetailField label="Last Edited By" value={`${delivery.updater.name} — ${formatDate(delivery.updated_at)}`} />
            )}
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={delivery.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />

      <ConfirmationDialog
        open={confirmingCancel}
        onOpenChange={setConfirmingCancel}
        title={`Cancel ${delivery.document_number ?? 'this Delivery'}?`}
        description={
          delivery.status === 'complete'
            ? 'Stock sent out with this delivery goes back to the warehouse, and the Sales Order\'s delivered quantity is reduced. The record is kept as cancelled.'
            : 'The delivery is kept as cancelled for the record. No stock is affected, since nothing was posted yet.'
        }
        confirmLabel="Cancel Delivery"
        variant="destructive"
        onConfirm={() => cancelMutation.mutate()}
      />
    </div>
  )
}
