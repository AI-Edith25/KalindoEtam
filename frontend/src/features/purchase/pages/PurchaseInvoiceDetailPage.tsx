import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, ExternalLink, FilePlus2, Loader2, Pencil, Send, Trash2 } from 'lucide-react'
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
import { formatCurrency, formatDate } from '@/lib/utils'
import { formatQty } from '@/shared/lib/qty'
import { cancelPurchaseInvoice, deletePurchaseInvoice, fetchPurchaseInvoice, submitPurchaseInvoice } from '../api/purchaseInvoiceApi'
import { PURCHASE_RETURN_REASON_LABELS } from '../lib/purchaseReturnReasonLabels'
import type { PurchaseInvoiceItem, PurchaseInvoicePurchaseReturnHistoryLine } from '../types'

const lineColumns: DataTableColumn<PurchaseInvoiceItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, row.item_qty_category ?? 'unit'), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

function buildGoodsReceiptColumns(navigate: (path: string) => void): DataTableColumn<{ id: string; document_number: string | null }>[] {
  return [
    {
      header: 'Goods Receipt No',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/purchase/goods-receipts/${row.id}`)
          }}
        >
          {row.document_number ?? '—'}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
  ]
}

function buildPurchaseOrderColumns(navigate: (path: string) => void): DataTableColumn<{ id: string; document_number: string | null }>[] {
  return [
    {
      header: 'Purchase Order No',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/purchase/orders/${row.id}`)
          }}
        >
          {row.document_number ?? '—'}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
  ]
}

function buildPurchaseReturnColumns(navigate: (path: string) => void): DataTableColumn<PurchaseInvoicePurchaseReturnHistoryLine>[] {
  return [
    {
      header: 'Return No',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/purchase/returns/${row.id}`)
          }}
        >
          {row.document_number ?? '—'}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    { header: 'Date', accessor: (row) => formatDate(row.return_date) },
    { header: 'Reason', accessor: (row) => PURCHASE_RETURN_REASON_LABELS[row.reason] },
    { header: 'Amount', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    {
      header: 'Status',
      accessor: (row) => (
        <div className="flex items-center gap-2">
          <StatusBadge status={row.status} />
          {row.is_reversed && <Badge variant="secondary">Reversed</Badge>}
        </div>
      ),
    },
  ]
}

export function PurchaseInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const invoiceQuery = useQuery({
    queryKey: ['purchase-invoices', id],
    queryFn: () => fetchPurchaseInvoice(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
    queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseInvoice(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Invoice submitted — Accounts Payable created.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelPurchaseInvoice(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Invoice cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deletePurchaseInvoice(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Invoice deleted.')
      navigate('/purchase/invoices')
    },
    onError: (error) => toastApiError(error),
  })

  if (invoiceQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const invoice = invoiceQuery.data
  if (!invoice) return null

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={invoice.document_number ?? 'Purchase Invoice'}
        description="Purchase Invoice details."
        actions={
          <div className="flex items-center gap-2">
            {invoice.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/purchase/invoices/${invoice.id}/edit`)}>
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
            {invoice.status === 'submitted' && Number(invoice.returnable_amount) > 0 && (
              <Button variant="outline" onClick={() => navigate(`/purchase/returns/new?purchase_invoice_id=${invoice.id}`)}>
                <FilePlus2 className="size-4" />
                Create Return
              </Button>
            )}
            {invoice.status === 'submitted' && (
              <Button variant="destructive" onClick={() => cancelMutation.mutate()} disabled={cancelMutation.isPending}>
                {cancelMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Ban className="size-4" />}
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Invoice Information</CardTitle>
          <StatusBadge status={invoice.display_status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Invoice Number" value={invoice.document_number ?? '—'} />
            <DetailField label="Invoice Date" value={formatDate(invoice.invoice_date)} />
            <DetailField label="Due Date" value={formatDate(invoice.due_date)} />
            <DetailField label="Reference Number" value={invoice.reference_number || '—'} />
            <DetailField label="Warehouse" value={invoice.goods_receipt?.warehouse?.name || '—'} />
            <DetailField label="Notes" value={invoice.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Supplier Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Supplier" value={invoice.supplier?.supplier_name ?? '—'} />
            <DetailField label="Supplier Code" value={invoice.supplier?.supplier_code ?? '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Goods Receipt Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable
            columns={buildGoodsReceiptColumns(navigate)}
            data={invoice.goods_receipts}
            rowKey={(row) => row.id}
            emptyMessage="No goods receipts linked."
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Purchase Order Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable
            columns={buildPurchaseOrderColumns(navigate)}
            data={invoice.purchase_orders}
            rowKey={(row) => row.id}
            emptyMessage="No purchase orders linked."
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Item List</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={invoice.items} rowKey={(row) => row.id} emptyMessage="No line items." />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col items-end gap-1.5 py-4">
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Subtotal</span>
            <span>{formatCurrency(invoice.subtotal)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Tax</span>
            <span>{formatCurrency(invoice.tax_amount)}</span>
          </div>
          <Separator className="w-full max-w-64" />
          <div className="flex w-full max-w-64 justify-between text-base font-semibold">
            <span>Grand Total</span>
            <span>{formatCurrency(invoice.grand_total)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Paid Amount</span>
            <span>{formatCurrency(invoice.paid_amount)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm font-medium">
            <span>Outstanding</span>
            <span>{formatCurrency(invoice.outstanding_amount)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Returned Amount</span>
            <span>{formatCurrency(invoice.credited_amount)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm font-medium">
            <span>Remaining Returnable</span>
            <span>{formatCurrency(invoice.returnable_amount)}</span>
          </div>
        </CardContent>
      </Card>

      {invoice.purchase_return_history.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Purchase Returns</CardTitle>
          </CardHeader>
          <CardContent>
            <DataTable
              columns={buildPurchaseReturnColumns(navigate)}
              data={invoice.purchase_return_history}
              rowKey={(row) => row.id}
              emptyMessage="No purchase returns issued yet."
            />
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(invoice.created_at)} />
            <DetailField label="Submitted" value={invoice.submitted_at ? formatDate(invoice.submitted_at) : '—'} />
            <DetailField label="Cancelled" value={invoice.cancelled_at ? formatDate(invoice.cancelled_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={invoice.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
