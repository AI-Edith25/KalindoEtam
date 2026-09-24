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
import { RupiahInput } from '@/components/shared/RupiahInput'
import { Textarea } from '@/components/ui/textarea'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { formatQty } from '@/shared/lib/qty'
import { computeLineTaxTotal, computeSubtotal } from '@/shared/lib/documentTotals'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { fetchSuppliersLookup, fetchTaxesLookup } from '@/features/master/api/lookupsApi'
import { fetchGoodsReceipts } from '../api/goodsReceiptApi'
import { createPurchaseInvoice, fetchPurchaseInvoice, submitPurchaseInvoice, updatePurchaseInvoice } from '../api/purchaseInvoiceApi'
import { DirectPurchaseInvoiceLineItemTable } from '../components/DirectPurchaseInvoiceLineItemTable'
import {
  emptyPurchaseInvoiceEditorValues,
  purchaseInvoiceFormSchema,
  type PurchaseInvoiceEditorValues,
  emptyDirectPurchaseInvoiceEditorValues,
  directPurchaseInvoiceFormSchema,
  type DirectPurchaseInvoiceEditorValues,
} from '../lib/purchaseInvoiceFormSchema'
import type { GoodsReceipt, PurchaseInvoice, PurchaseInvoiceFormValues, PurchaseInvoiceItem } from '../types'

interface PreviewLine {
  id: string
  item_code: string | null
  item_name: string
  uom: string | null
  qty: string | number
  qty_category?: 'unit' | 'weight'
  rate: string | number
  amount: string | number
}

const lineColumns: DataTableColumn<PreviewLine>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, row.qty_category ?? 'unit'), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

type InvoiceMode = 'from_gr' | 'direct'

export function PurchaseInvoiceEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [mode, setMode] = useState<InvoiceMode | null>(null)
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

  // An existing invoice's own source decides its mode — a Direct invoice can't gain a Goods
  // Receipt on edit, and vice versa (create() branches once, at creation, only).
  const isDirectMode = isEdit ? invoiceQuery.data?.source === 'direct' : mode === 'direct'

  // Eligible = submitted (stock already moved) and not yet invoiced. Fetched only in create mode, From Goods Receipt.
  const eligibleGoodsReceiptsQuery = useQuery({
    queryKey: ['goods-receipts-eligible-for-invoice'],
    queryFn: () => fetchGoodsReceipts({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit && mode === 'from_gr',
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

  // Step 1 (create mode only): choose From Goods Receipt or Direct/Non-Stock, then (for From
  // Goods Receipt) select one or more Goods Receipts.
  if (!isEdit && (mode === null || (mode === 'from_gr' && !selectionConfirmed))) {
    const allSelectableChecked = selectableGoodsReceipts.length > 0 && selectableGoodsReceipts.every((gr) => selectedGoodsReceiptIds.has(gr.id))

    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="New Invoice"
          description="Invoice one or more received Goods Receipts, or record a Direct/Non-Stock invoice with no Goods Receipt (e.g. vehicle repairs, services)."
        />
        <Card>
          <CardHeader>
            <CardTitle>{mode === 'from_gr' ? 'Select Goods Receipt' : 'How was this invoiced?'}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {mode === null ? (
              <div className="flex gap-2">
                <Button type="button" onClick={() => setMode('from_gr')}>
                  From Goods Receipt
                </Button>
                <Button type="button" variant="outline" onClick={() => setMode('direct')}>
                  Direct / Non-Stock (no Goods Receipt)
                </Button>
              </div>
            ) : (
              <>
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
                    Only submitted Goods Receipts that have not already been invoiced are shown — once you select one, only Goods Receipts from the
                    same Supplier remain selectable.
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button type="button" variant="outline" onClick={() => setMode(null)}>
                    Back
                  </Button>
                  <Button type="button" disabled={selectedGoodsReceiptIds.size === 0} onClick={() => setSelectionConfirmed(true)}>
                    Continue
                  </Button>
                </div>
              </>
            )}
            <Button type="button" variant="outline" className="self-start" onClick={() => navigate('/purchase/invoices')}>
              Cancel
            </Button>
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

  if (isDirectMode) {
    return (
      <DirectPurchaseInvoiceForm
        isEdit={isEdit}
        id={id}
        invoice={invoiceQuery.data}
        navigate={navigate}
        queryClient={queryClient}
      />
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
    ? ((invoice?.items ?? []) as PurchaseInvoiceItem[]).map((line) => ({ ...line, qty_category: line.item_qty_category }))
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
                      <RupiahInput value={field.value} onChange={field.onChange} />
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

/** Direct/Non-Stock invoice — no Goods Receipt, per-line Chart-of-Accounts expense account + own tax. */
function DirectPurchaseInvoiceForm({
  isEdit,
  id,
  invoice,
  navigate,
  queryClient,
}: {
  isEdit: boolean
  id: string | undefined
  invoice: PurchaseInvoice | undefined
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup })
  const supplierOptions = suppliers.data?.map((supplier) => ({ value: supplier.id, label: supplier.supplier_name })) ?? []
  const accounts = useChartOfAccountsLookup()
  const expenseAccounts = (accounts.data ?? []).filter((account) => account.account_type === 'expense' && account.is_active)
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  const activePurchaseTaxOptions = (taxesQuery.data ?? []).filter((t) => t.is_active && t.transaction_type === 'purchase')

  const form = useForm<DirectPurchaseInvoiceEditorValues>({
    resolver: zodResolver(directPurchaseInvoiceFormSchema),
    defaultValues: invoice
      ? {
          supplier_id: invoice.supplier_id,
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          reference_number: invoice.reference_number ?? '',
          attention: invoice.attention ?? '',
          department: invoice.department ?? '',
          remarks: invoice.remarks ?? '',
          items: (invoice.items ?? []).map((line) => ({
            chart_of_account_id: line.chart_of_account_id ?? '',
            description: line.item_name,
            uom: line.uom ?? '',
            qty: String(line.qty),
            rate: String(line.rate),
            tax_id: line.tax_id ?? '',
          })),
        }
      : emptyDirectPurchaseInvoiceEditorValues,
  })

  const toPayload = (values: DirectPurchaseInvoiceEditorValues): PurchaseInvoiceFormValues => ({
    ...(isEdit ? {} : { source: 'direct' as const }),
    supplier_id: values.supplier_id,
    invoice_date: values.invoice_date,
    due_date: values.due_date || null,
    tax_amount: null,
    reference_number: values.reference_number || null,
    attention: values.attention || null,
    department: values.department || null,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      chart_of_account_id: line.chart_of_account_id,
      description: line.description,
      uom: line.uom || null,
      qty: Number(line.qty),
      rate: Number(line.rate),
      tax_id: line.tax_id || null,
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: DirectPurchaseInvoiceEditorValues) => {
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

  const watchedItems = form.watch('items')
  const subtotal = computeSubtotal(watchedItems ?? [])
  const tax = computeLineTaxTotal(watchedItems ?? [], (line) => activePurchaseTaxOptions.find((t) => t.id === line.tax_id))
  const grandTotal = subtotal + tax

  const onSubmit = form.handleSubmit((values) => saveMutation.mutate(values))

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${invoice?.document_number ?? 'Invoice'}` : 'New Direct Invoice'}
        description="Direct/Non-Stock invoice — no source Goods Receipt, lines post straight to an expense account."
      />

      <Form {...form}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Invoice Details</CardTitle>
              <StatusBadge status={isEdit ? (invoice?.display_status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5">
                <span className="text-xs text-muted-foreground">Invoice Number</span>
                <span className="text-sm font-medium">{isEdit ? (invoice?.document_number ?? '—') : 'Assigned when saved'}</span>
              </div>
              <div />
              <FormField
                control={form.control}
                name="supplier_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Supplier</FormLabel>
                    <SearchableSelect
                      options={supplierOptions}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={suppliers.isLoading}
                      clearable={false}
                      placeholder="Select supplier"
                      aria-label="Supplier"
                    />
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
                    <p className="text-xs text-muted-foreground">Leave blank to use the Supplier's Terms of Payment.</p>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="attention"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Attention</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. vehicle plate number" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="department"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Department</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
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
              <DirectPurchaseInvoiceLineItemTable form={form} accounts={expenseAccounts} accountsLoading={accounts.isLoading} taxes={activePurchaseTaxOptions} />
              {form.formState.errors.items?.root && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.root.message}</p>
              )}
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
