import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, ExternalLink, FilePlus2, Loader2, Pencil, Printer, Send, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { PAYMENT_METHOD_LABELS } from '@/features/payment/lib/paymentMethodLabels'
import type { PaymentMethod } from '@/features/payment/types'
import { cancelInvoice, deleteInvoice, fetchInvoice, submitInvoice } from '../api/invoiceApi'
import { CREDIT_NOTE_REASON_LABELS } from '../lib/creditNoteReasonLabels'
import { DEBIT_NOTE_REASON_LABELS } from '../lib/debitNoteReasonLabels'
import type { InvoiceCreditNoteHistoryLine, InvoiceDebitNoteHistoryLine, InvoiceItem, InvoicePaymentHistoryLine } from '../types'

const lineColumns: DataTableColumn<InvoiceItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

function buildPaymentColumns(navigate: (path: string) => void): DataTableColumn<InvoicePaymentHistoryLine>[] {
  return [
    {
      header: 'Receipt No',
      accessor: (row) =>
        row.receipt_entry_document_number ? (
          <Button
            variant="link"
            className="h-auto p-0"
            onClick={(event) => {
              event.stopPropagation()
              navigate(`/finance/incoming/${row.receipt_entry_id}`)
            }}
          >
            {row.receipt_entry_document_number}
            <ExternalLink className="size-3.5" />
          </Button>
        ) : (
          '—'
        ),
    },
    { header: 'Date', accessor: (row) => formatDate(row.receipt_date) },
    { header: 'Method', accessor: (row) => PAYMENT_METHOD_LABELS[row.payment_method as PaymentMethod] ?? row.payment_method },
    { header: 'Amount', accessor: (row) => formatCurrency(row.received_amount), className: 'text-right' },
  ]
}

function buildCreditNoteColumns(navigate: (path: string) => void): DataTableColumn<InvoiceCreditNoteHistoryLine>[] {
  return [
    {
      header: 'Credit Note No',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/sales/credit-notes/${row.id}`)
          }}
        >
          {row.document_number ?? '—'}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    { header: 'Date', accessor: (row) => formatDate(row.credit_note_date) },
    { header: 'Reason', accessor: (row) => CREDIT_NOTE_REASON_LABELS[row.reason] },
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

function buildDebitNoteColumns(navigate: (path: string) => void): DataTableColumn<InvoiceDebitNoteHistoryLine>[] {
  return [
    {
      header: 'Debit Note No',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/sales/debit-notes/${row.id}`)
          }}
        >
          {row.document_number ?? '—'}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    { header: 'Date', accessor: (row) => formatDate(row.debit_note_date) },
    { header: 'Reason', accessor: (row) => DEBIT_NOTE_REASON_LABELS[row.reason] },
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

export function InvoiceDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['invoices'] })
    queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitInvoice(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Invoice submitted — Accounts Receivable created.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelInvoice(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Invoice cancelled.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteInvoice(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Invoice deleted.')
      navigate('/sales/invoices')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
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
        title={invoice.document_number ?? 'Invoice'}
        description="Invoice details."
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => navigate(`/sales/invoices/${invoice.id}/print`)}>
              <Printer className="size-4" />
              Print
            </Button>
            {invoice.status === 'draft' && (
              <>
                <Button variant="outline" onClick={() => navigate(`/sales/invoices/${invoice.id}/edit`)}>
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
            {invoice.status === 'submitted' && Number(invoice.creditable_amount) > 0 && (
              <Button variant="outline" onClick={() => navigate(`/sales/credit-notes/new?invoice_id=${invoice.id}`)}>
                <FilePlus2 className="size-4" />
                Create Credit Note
              </Button>
            )}
            {invoice.status === 'submitted' && (
              <Button variant="outline" onClick={() => navigate(`/sales/debit-notes/new?invoice_id=${invoice.id}`)}>
                <FilePlus2 className="size-4" />
                Create Debit Note
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
            <DetailField label="Notes" value={invoice.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Customer Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Customer" value={invoice.customer?.customer_name ?? '—'} />
            <DetailField label="Phone" value={invoice.customer?.phone || '—'} />
            <DetailField label="Address" value={invoice.customer?.address || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Delivery Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailField
            label="Delivery"
            value={
              invoice.delivery ? (
                <Button variant="link" className="h-auto p-0" onClick={() => navigate(`/sales/deliveries/${invoice.delivery_id}`)}>
                  {invoice.delivery.document_number ?? '—'}
                  <ExternalLink className="size-3.5" />
                </Button>
              ) : (
                '—'
              )
            }
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
            <span className="text-muted-foreground">Discount</span>
            <span>-{formatCurrency(invoice.discount_amount)}</span>
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
            <span className="text-muted-foreground">Credited Amount</span>
            <span>{formatCurrency(invoice.credited_amount)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm">
            <span className="text-muted-foreground">Debited Amount</span>
            <span>{formatCurrency(invoice.debited_amount)}</span>
          </div>
          <div className="flex w-full max-w-64 justify-between text-sm font-medium">
            <span>Remaining Creditable</span>
            <span>{formatCurrency(invoice.creditable_amount)}</span>
          </div>
        </CardContent>
      </Card>

      {invoice.payment_history.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Payment History</CardTitle>
          </CardHeader>
          <CardContent>
            <DataTable
              columns={buildPaymentColumns(navigate)}
              data={invoice.payment_history}
              rowKey={(row) => row.id}
              emptyMessage="No payments received yet."
            />
          </CardContent>
        </Card>
      )}

      {invoice.credit_note_history.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Credit Notes</CardTitle>
          </CardHeader>
          <CardContent>
            <DataTable
              columns={buildCreditNoteColumns(navigate)}
              data={invoice.credit_note_history}
              rowKey={(row) => row.id}
              emptyMessage="No credit notes issued yet."
            />
          </CardContent>
        </Card>
      )}

      {invoice.debit_note_history.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Debit Notes</CardTitle>
          </CardHeader>
          <CardContent>
            <DataTable
              columns={buildDebitNoteColumns(navigate)}
              data={invoice.debit_note_history}
              rowKey={(row) => row.id}
              emptyMessage="No debit notes issued yet."
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
