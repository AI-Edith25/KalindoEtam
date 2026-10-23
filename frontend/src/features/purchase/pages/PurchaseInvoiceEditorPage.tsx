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
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { computeLineTaxTotal } from '@/shared/lib/documentTotals'
import { purchaseInvoiceLineAmount } from '../lib/directLineAmount'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { fetchTaxesLookup, searchSuppliersLookup } from '@/features/master/api/lookupsApi'
import { fetchGoodsReceipts } from '../api/goodsReceiptApi'
import { createPurchaseInvoice, fetchPurchaseInvoice, submitPurchaseInvoice, updatePurchaseInvoice } from '../api/purchaseInvoiceApi'
import { PurchaseInvoiceLineItemTable } from '../components/PurchaseInvoiceLineItemTable'
import {
  emptyPurchaseInvoiceEditorValues,
  purchaseInvoiceFormSchema,
  type PurchaseInvoiceEditorValues,
  type PurchaseInvoiceLineRow,
} from '../lib/purchaseInvoiceFormSchema'
import type { GoodsReceipt, PurchaseInvoice, PurchaseInvoiceFormValues, PurchaseInvoiceItem } from '../types'

export function PurchaseInvoiceEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [supplierOption, setSupplierOption] = useState<SearchableSelectOption | undefined>()
  const [supplierConfirmed, setSupplierConfirmed] = useState(false)
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

  const loadSupplierOptions = async (query: string) => {
    const suppliers = await searchSuppliersLookup(query)
    return suppliers.map((supplier) => ({ value: supplier.id, label: supplier.supplier_name }))
  }

  const invoiceQuery = useQuery({
    queryKey: ['purchase-invoices', id],
    queryFn: () => fetchPurchaseInvoice(id!),
    enabled: isEdit,
  })

  // Eligible = submitted (stock already moved) and not yet invoiced. Fetched only in create mode,
  // once a Supplier has been chosen and before the selection is confirmed (Skip or Continue).
  const eligibleGoodsReceiptsQuery = useQuery({
    queryKey: ['goods-receipts-eligible-for-invoice'],
    queryFn: () => fetchGoodsReceipts({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit && supplierConfirmed && !selectionConfirmed,
  })
  const eligibleGoodsReceipts = (eligibleGoodsReceiptsQuery.data?.data ?? []).filter((goodsReceipt) => !goodsReceipt.is_invoiced)
  const selectableGoodsReceipts = supplierOption
    ? eligibleGoodsReceipts.filter((goodsReceipt) => goodsReceipt.supplier_id === supplierOption.value)
    : eligibleGoodsReceipts
  const selectedGoodsReceipts = selectableGoodsReceipts.filter((goodsReceipt) => selectedGoodsReceiptIds.has(goodsReceipt.id))

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

  // Step 1 (create mode only): choose the Supplier.
  if (!isEdit && !supplierConfirmed) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="New Invoice"
          description="Pick the Supplier being invoiced, then optionally select one or more received Goods Receipts and/or add non-stock lines."
        />
        <Card>
          <CardHeader>
            <CardTitle>Select Supplier</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <SearchableSelect
              loadOptions={loadSupplierOptions}
              selectedOption={supplierOption}
              value={supplierOption?.value}
              onChange={(_value, option) => setSupplierOption(option)}
              clearable={false}
              placeholder="Select supplier"
              aria-label="Supplier"
            />
            <div className="flex gap-2">
              <Button type="button" disabled={!supplierOption} onClick={() => setSupplierConfirmed(true)}>
                Continue
              </Button>
              <Button type="button" variant="outline" onClick={() => navigate('/purchase/invoices')}>
                Cancel
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  // Step 2 (create mode only): select Goods Receipt(s) — optional, Skip is always available.
  if (!isEdit && !selectionConfirmed) {
    const allSelectableChecked = selectableGoodsReceipts.length > 0 && selectableGoodsReceipts.every((gr) => selectedGoodsReceiptIds.has(gr.id))

    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Invoice" description={`Invoicing ${supplierOption?.label ?? ''}.`} />
        <Card>
          <CardHeader>
            <CardTitle>Select Goods Receipt (optional)</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              {eligibleGoodsReceiptsQuery.isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="size-6 animate-spin text-muted-foreground" />
                </div>
              ) : selectableGoodsReceipts.length === 0 ? (
                <p className="text-sm text-muted-foreground">No goods receipts available to invoice for this supplier.</p>
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
                Only submitted Goods Receipts from this Supplier that have not already been invoiced are shown. You can also skip this step and/or
                add non-stock lines on the next screen.
              </p>
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => setSupplierConfirmed(false)}>
                Back
              </Button>
              <Button type="button" variant="outline" onClick={() => setSelectionConfirmed(true)}>
                Skip / Continue without Goods Receipt
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
      key={isEdit ? id : `${supplierOption?.value ?? ''}:${Array.from(selectedGoodsReceiptIds).sort().join(',')}`}
      isEdit={isEdit}
      id={id}
      invoice={invoiceQuery.data}
      supplierOption={supplierOption}
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
  supplierOption,
  selectedGoodsReceipts,
  navigate,
  queryClient,
}: {
  isEdit: boolean
  id: string | undefined
  invoice: PurchaseInvoice | undefined
  supplierOption: SearchableSelectOption | undefined
  selectedGoodsReceipts: GoodsReceipt[]
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const accounts = useChartOfAccountsLookup()
  const directLineAccounts = (accounts.data ?? []).filter(
    (account) => (account.account_type === 'expense' || account.account_type === 'liability') && account.is_active,
  )
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  const activePurchaseTaxOptions = (taxesQuery.data ?? []).filter((t) => t.is_active && t.transaction_type === 'purchase')

  // Stock lines are immutable post-create — Supplier can only change while there are none yet
  // (mirrors PurchaseInvoiceService::update()'s own rule).
  const hasStockLines = isEdit ? (invoice?.items ?? []).some((line) => line.goods_receipt_item_id !== null) : selectedGoodsReceipts.length > 0
  const supplierEditable = isEdit && !hasStockLines

  const [selectedSupplierOption, setSelectedSupplierOption] = useState<SearchableSelectOption | undefined>(
    isEdit && invoice?.supplier ? { value: invoice.supplier.id, label: invoice.supplier.supplier_name } : supplierOption,
  )
  const loadSupplierOptions = async (query: string) => {
    const suppliers = await searchSuppliersLookup(query)
    return suppliers.map((supplier) => ({ value: supplier.id, label: supplier.supplier_name }))
  }

  const stockRows: PurchaseInvoiceLineRow[] = isEdit
    ? ((invoice?.items ?? []) as PurchaseInvoiceItem[])
        .filter((line) => line.goods_receipt_item_id !== null)
        .map((line) => ({
          kind: 'stock' as const,
          goods_receipt_item_id: line.goods_receipt_item_id!,
          item_code: line.item_code,
          item_name: line.item_name,
          uom: line.uom,
          qty: line.qty,
          qty_category: line.item_qty_category,
          rate: String(line.rate),
        }))
    : selectedGoodsReceipts.flatMap((gr) =>
        gr.items.map((line) => ({
          kind: 'stock' as const,
          goods_receipt_item_id: line.id,
          item_code: line.item_code,
          item_name: line.item_name,
          uom: line.uom,
          qty: line.qty,
          qty_category: line.qty_category,
          rate: String(line.rate),
        })),
      )

  const nonStockRows: PurchaseInvoiceLineRow[] = isEdit
    ? ((invoice?.items ?? []) as PurchaseInvoiceItem[])
        .filter((line) => line.goods_receipt_item_id === null)
        .map((line) => ({
          kind: 'non_stock' as const,
          chart_of_account_id: line.chart_of_account_id ?? '',
          description: line.item_name,
          uom: line.uom ?? '',
          qty: String(line.qty),
          rate: String(line.rate),
          tax_id: line.tax_id ?? '',
        }))
    : []

  const form = useForm<PurchaseInvoiceEditorValues>({
    resolver: zodResolver(purchaseInvoiceFormSchema),
    defaultValues: invoice
      ? {
          supplier_id: invoice.supplier_id,
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          tax_amount: String(invoice.tax_amount),
          reference_number: invoice.reference_number ?? '',
          attention: invoice.attention ?? '',
          department: invoice.department ?? '',
          remarks: invoice.remarks ?? '',
          items: [...stockRows, ...nonStockRows],
        }
      : { ...emptyPurchaseInvoiceEditorValues, supplier_id: supplierOption?.value ?? '', items: stockRows },
  })

  const toPayload = (values: PurchaseInvoiceEditorValues): PurchaseInvoiceFormValues => {
    const stockLines = values.items.filter((line) => line.kind === 'stock')
    const nonStockLines = values.items.filter((line) => line.kind === 'non_stock')

    return {
      supplier_id: values.supplier_id,
      // Immutable once created — only sent on create.
      ...(isEdit ? {} : { goods_receipt_ids: selectedGoodsReceipts.map((gr) => gr.id) }),
      invoice_date: values.invoice_date,
      due_date: values.due_date || null,
      tax_amount: values.tax_amount === '' ? 0 : Number(values.tax_amount),
      reference_number: values.reference_number || null,
      attention: values.attention || null,
      department: values.department || null,
      remarks: values.remarks || null,
      ...(stockLines.length > 0
        ? { item_rates: stockLines.map((line) => ({ goods_receipt_item_id: line.goods_receipt_item_id, rate: Number(line.rate) })) }
        : {}),
      ...(nonStockLines.length > 0
        ? {
            items: nonStockLines.map((line) => ({
              chart_of_account_id: line.chart_of_account_id,
              description: line.description,
              uom: line.uom || null,
              qty: Number(line.qty),
              rate: Number(line.rate),
              tax_id: line.tax_id || null,
            })),
          }
        : {}),
    }
  }

  const saveMutation = useMutation({
    mutationFn: (values: PurchaseInvoiceEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updatePurchaseInvoice(id!, payload) : createPurchaseInvoice(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
      toast.success(isEdit ? 'Purchase Invoice updated.' : 'Purchase Invoice saved as draft.')
      navigate('/purchase/invoices')
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseInvoice(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Purchase Invoice submitted — Accounts Payable created.')
      navigate('/purchase/invoices')
    },
    onError: (error) => toastApiError(error),
  })

  const watchedTaxAmount = Number(form.watch('tax_amount') || 0)
  const watchedItems = form.watch('items')
  const subtotal = (watchedItems ?? []).reduce((sum, line) => sum + purchaseInvoiceLineAmount(line, directLineAccounts), 0)
  const lineTax = computeLineTaxTotal(watchedItems ?? [], (line) =>
    line.kind === 'non_stock' ? activePurchaseTaxOptions.find((t) => t.id === line.tax_id) : undefined,
  )
  const tax = watchedTaxAmount + lineTax
  const grandTotal = subtotal + tax

  const onSubmit = form.handleSubmit((values) => saveMutation.mutate(values))

  const goodsReceiptLabel = isEdit
    ? invoice?.goods_receipts?.map((gr) => gr.document_number).join(', ')
    : selectedGoodsReceipts.map((gr) => gr.document_number).join(', ')

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${invoice?.document_number ?? 'Invoice'}` : 'New Invoice'}
        description="Invoice one or more Goods Receipts, add non-stock lines, or both."
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
              {goodsReceiptLabel && (
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">Goods Receipt</span>
                  <span className="text-sm font-medium">{goodsReceiptLabel}</span>
                </div>
              )}
              <FormField
                control={form.control}
                name="supplier_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Supplier</FormLabel>
                    <SearchableSelect
                      loadOptions={loadSupplierOptions}
                      selectedOption={selectedSupplierOption}
                      value={field.value}
                      onChange={(value, option) => {
                        field.onChange(value ?? '')
                        setSelectedSupplierOption(option)
                      }}
                      clearable={false}
                      disabled={!supplierEditable}
                      placeholder="Select supplier"
                      aria-label="Supplier"
                    />
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
                    <FormLabel>Tax Amount (Goods Receipt lines)</FormLabel>
                    <FormControl>
                      <RupiahInput value={field.value} onChange={field.onChange} decimals={2} />
                    </FormControl>
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
              <PurchaseInvoiceLineItemTable
                form={form}
                accounts={directLineAccounts}
                accountsLoading={accounts.isLoading}
                taxes={activePurchaseTaxOptions}
              />
              {form.formState.errors.items?.root && <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.root.message}</p>}
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
