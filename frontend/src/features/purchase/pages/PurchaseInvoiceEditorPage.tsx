import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import type { NavigateFunction } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save, Send } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchGoodsReceipts } from '../api/goodsReceiptApi'
import { createPurchaseInvoice, fetchPurchaseInvoice, submitPurchaseInvoice, updatePurchaseInvoice } from '../api/purchaseInvoiceApi'
import { emptyPurchaseInvoiceEditorValues, purchaseInvoiceFormSchema, type PurchaseInvoiceEditorValues } from '../lib/purchaseInvoiceFormSchema'
import type { GoodsReceipt, PurchaseInvoice, PurchaseInvoiceFormValues, PurchaseInvoiceItem } from '../types'

interface PreviewLine {
  id: string
  item_code: string | null
  item_name: string
  uom: string | null
  qty: number
  rate: string | number
  amount: string | number
}

const lineColumns: DataTableColumn<PreviewLine>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

export function PurchaseInvoiceEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selectedGoodsReceiptIds, setSelectedGoodsReceiptIds] = useState<Set<string>>(new Set())
  const [selectionConfirmed, setSelectionConfirmed] = useState(false)

  const toggleGoodsReceipt = (goodsReceiptId: string, checked: boolean) => {
    setSelectedGoodsReceiptIds((prev) => {
      const next = new Set(prev)
      if (checked) next.add(goodsReceiptId)
      else next.delete(goodsReceiptId)
      return next
    })
  }

  const invoiceQuery = useQuery({
    queryKey: ['purchase-invoices', id],
    queryFn: () => fetchPurchaseInvoice(id!),
    enabled: isEdit,
  })

  // Eligible = submitted (stock already moved) and not yet invoiced. Fetched only in create mode.
  const eligibleGoodsReceiptsQuery = useQuery({
    queryKey: ['goods-receipts-eligible-for-invoice'],
    queryFn: () => fetchGoodsReceipts({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit,
  })
  const eligibleGoodsReceipts = (eligibleGoodsReceiptsQuery.data?.data ?? []).filter((goodsReceipt) => !goodsReceipt.is_invoiced)
  const selectedGoodsReceipts = eligibleGoodsReceipts.filter((goodsReceipt) => selectedGoodsReceiptIds.has(goodsReceipt.id))
  // Once ≥1 Goods Receipt is checked, only Goods Receipts from the same Supplier remain selectable.
  const selectedSupplierId = selectedGoodsReceipts[0]?.supplier_id ?? null
  const selectableGoodsReceipts = selectedSupplierId
    ? eligibleGoodsReceipts.filter((goodsReceipt) => goodsReceipt.supplier_id === selectedSupplierId)
    : eligibleGoodsReceipts

  useEffect(() => {
    const invoice = invoiceQuery.data
    if (!invoice) return

    if (invoice.status !== 'draft') {
      toast.error('Only draft invoices can be edited.')
      navigate(`/purchase/invoices/${invoice.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [invoiceQuery.data])

  if (isEdit && invoiceQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): pick one or more Goods Receipts this invoice originates from.
  if (!isEdit && !selectionConfirmed) {
    const allSelectableChecked = selectableGoodsReceipts.length > 0 && selectableGoodsReceipts.every((gr) => selectedGoodsReceiptIds.has(gr.id))

    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="New Invoice"
          description="A Purchase Invoice can combine one or more received, not-yet-invoiced Goods Receipts from the same Supplier."
        />
        <Card>
          <CardHeader>
            <CardTitle>Select Goods Receipt</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              {eligibleGoodsReceiptsQuery.isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="size-6 animate-spin text-muted-foreground" />
                </div>
              ) : selectableGoodsReceipts.length === 0 ? (
                <p className="text-sm text-muted-foreground">No goods receipts available to invoice.</p>
              ) : (
                <div className="overflow-x-auto rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="w-10">
                          <Checkbox
                            checked={allSelectableChecked}
                            onCheckedChange={(checked) => selectableGoodsReceipts.forEach((gr) => toggleGoodsReceipt(gr.id, checked === true))}
                            aria-label="Select all eligible goods receipts"
                          />
                        </TableHead>
                        <TableHead>Document Number</TableHead>
                        <TableHead>Supplier</TableHead>
                        <TableHead>Receipt Date</TableHead>
                        <TableHead className="text-right">Items</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {selectableGoodsReceipts.map((goodsReceipt) => {
                        const checked = selectedGoodsReceiptIds.has(goodsReceipt.id)
                        return (
                          <TableRow key={goodsReceipt.id} data-state={checked ? 'selected' : undefined}>
                            <TableCell>
                              <Checkbox
                                checked={checked}
                                onCheckedChange={(value) => toggleGoodsReceipt(goodsReceipt.id, value === true)}
                                aria-label={`Select ${goodsReceipt.document_number}`}
                              />
                            </TableCell>
                            <TableCell className="font-medium">{goodsReceipt.document_number}</TableCell>
                            <TableCell>{goodsReceipt.supplier?.supplier_name}</TableCell>
                            <TableCell>{goodsReceipt.receipt_date}</TableCell>
                            <TableCell className="text-right">{goodsReceipt.items.length}</TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              )}
              <p className="text-sm text-muted-foreground">
                Only submitted Goods Receipts that have not already been invoiced are shown — once you select one, only Goods Receipts from the same
                Supplier remain selectable.
              </p>
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => navigate('/purchase/invoices')}>
                Cancel
              </Button>
              <Button type="button" disabled={selectedGoodsReceiptIds.size === 0} onClick={() => setSelectionConfirmed(true)}>
                Continue
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  if (isEdit && !invoiceQuery.data) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <PurchaseInvoiceForm
      key={isEdit ? id : Array.from(selectedGoodsReceiptIds).sort().join(',')}
      isEdit={isEdit}
      id={id}
      invoice={invoiceQuery.data}
      selectedGoodsReceipts={selectedGoodsReceipts}
      navigate={navigate}
      queryClient={queryClient}
    />
  )
}

function PurchaseInvoiceForm({
  isEdit,
  id,
  invoice,
  selectedGoodsReceipts,
  navigate,
  queryClient,
}: {
  isEdit: boolean
  id: string | undefined
  invoice: PurchaseInvoice | undefined
  selectedGoodsReceipts: GoodsReceipt[]
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const form = useForm<PurchaseInvoiceEditorValues>({
    resolver: zodResolver(purchaseInvoiceFormSchema),
    defaultValues: invoice
      ? {
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          tax_amount: String(invoice.tax_amount),
          reference_number: invoice.reference_number ?? '',
          remarks: invoice.remarks ?? '',
        }
      : emptyPurchaseInvoiceEditorValues,
  })

  const toPayload = (values: PurchaseInvoiceEditorValues): PurchaseInvoiceFormValues => ({
    // Immutable once created — only sent on create; UpdatePurchaseInvoiceRequest doesn't accept it.
    ...(isEdit ? {} : { goods_receipt_ids: selectedGoodsReceipts.map((gr) => gr.id) }),
    invoice_date: values.invoice_date,
    due_date: values.due_date,
    tax_amount: values.tax_amount === '' ? 0 : Number(values.tax_amount),
    reference_number: values.reference_number || null,
    remarks: values.remarks || null,
  })

  const saveMutation = useMutation({
    mutationFn: (values: PurchaseInvoiceEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updatePurchaseInvoice(id!, payload) : createPurchaseInvoice(payload)
    },
    onSuccess: (savedInvoice) => {
      queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
      toast.success(isEdit ? 'Purchase Invoice updated.' : 'Purchase Invoice saved as draft.')
      if (!isEdit) {
        navigate(`/purchase/invoices/${savedInvoice.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseInvoice(id!),
    onSuccess: (submittedInvoice) => {
      queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Purchase Invoice submitted — Accounts Payable created.')
      navigate(`/purchase/invoices/${submittedInvoice.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const watchedTaxAmount = Number(form.watch('tax_amount') || 0)

  const previewLines: PreviewLine[] = isEdit
    ? ((invoice?.items ?? []) as PurchaseInvoiceItem[]).map((line) => ({ ...line }))
    : selectedGoodsReceipts.flatMap((gr) => gr.items.map((line) => ({ ...line })))
  const subtotal = previewLines.reduce((sum, line) => sum + Number(line.amount), 0)
  const grandTotal = subtotal + watchedTaxAmount

  const onSubmit = form.handleSubmit((values) => saveMutation.mutate(values))

  const goodsReceiptLabel = isEdit
    ? invoice?.goods_receipts?.map((gr) => gr.document_number).join(', ')
    : selectedGoodsReceipts.map((gr) => gr.document_number).join(', ')
  const supplierName = isEdit ? invoice?.supplier?.supplier_name : selectedGoodsReceipts[0]?.supplier?.supplier_name

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${invoice?.document_number ?? 'Invoice'}` : 'New Invoice'}
        description={`Invoicing ${goodsReceiptLabel ?? ''} — ${supplierName ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Invoice Details</CardTitle>
              <StatusBadge status={isEdit ? (invoice?.display_status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Goods Receipt</span>
                <span className="text-sm font-medium">
                  {goodsReceiptLabel} — {supplierName}
                </span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-xs text-muted-foreground">Invoice Number</span>
                <span className="text-sm font-medium">{isEdit ? (invoice?.document_number ?? '—') : 'Assigned when saved'}</span>
              </div>
              <FormField
                control={form.control}
                name="invoice_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Invoice Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="due_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Due Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reference_number"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reference Number</FormLabel>
                    <FormControl>
                      <Input placeholder="Supplier's own invoice number" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="tax_amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tax Amount</FormLabel>
                    <FormControl>
                      <Input type="number" min="0" step="0.01" placeholder="0" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="remarks"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Notes</FormLabel>
                    <FormControl>
                      <Textarea placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Line Items</CardTitle>
            </CardHeader>
            <CardContent>
              <DataTable columns={lineColumns} data={previewLines} rowKey={(row) => row.id} emptyMessage="No line items." />
              <p className="mt-2 text-sm text-muted-foreground">
                Items are copied from the Goods Receipt and cannot be changed here — cancel and re-invoice if the Goods Receipt was wrong.
              </p>
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
                <span>{formatCurrency(watchedTaxAmount)}</span>
              </div>
              <Separator className="w-full max-w-64" />
              <div className="flex w-full max-w-64 justify-between text-base font-semibold">
                <span>Grand Total</span>
                <span>{formatCurrency(grandTotal)}</span>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/purchase/invoices')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && invoice?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Submit
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
