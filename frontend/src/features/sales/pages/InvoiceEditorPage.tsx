import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save, Send } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchTaxesLookup } from '@/features/master/api/lookupsApi'
import { fetchDeliveries } from '../api/deliveryApi'
import { createInvoice, fetchInvoice, submitInvoice, updateInvoice } from '../api/invoiceApi'
import { emptyInvoiceEditorValues, invoiceFormSchema, type InvoiceEditorValues } from '../lib/invoiceFormSchema'
import { INVOICE_TYPE_LABELS, INVOICE_TYPE_OPTIONS } from '../lib/invoiceTypeLabels'
import { discountLabel } from '../lib/discount'
import type { InvoiceFormValues, InvoiceType } from '../types'

/** Digits-only value in RHF (string-then-convert), formatted with Indonesian thousand separators while editing. */
function RupiahInput({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const display = value ? new Intl.NumberFormat('id-ID').format(Number(value)) : ''
  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">Rp</span>
      <Input
        className="pl-9"
        inputMode="numeric"
        placeholder="0"
        value={display}
        onChange={(event) => onChange(event.target.value.replace(/\D/g, ''))}
      />
    </div>
  )
}

const NO_TAX = '__none__'

interface PreviewLine {
  id: string
  item_code: string
  item_name: string
  uom: string
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

export function InvoiceEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selectedDeliveryId, setSelectedDeliveryId] = useState<string | null>(null)
  const [selectedInvoiceType, setSelectedInvoiceType] = useState<InvoiceType | null>(null)

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
    enabled: isEdit,
  })

  // Eligible = submitted and not yet invoiced. Fetched only in create mode, before a Delivery is picked.
  const eligibleDeliveriesQuery = useQuery({
    queryKey: ['deliveries-eligible-for-invoice'],
    queryFn: () => fetchDeliveries({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit,
  })
  const eligibleDeliveries = (eligibleDeliveriesQuery.data?.data ?? []).filter((delivery) => !delivery.is_invoiced)
  const selectedDelivery = eligibleDeliveries.find((delivery) => delivery.id === selectedDeliveryId) ?? null

  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  // Only Active taxes may be selected for a new/changed assignment (docs/TAX_ENGINE_DESIGN.md §9)
  // — but an invoice already referencing a since-deactivated tax must keep showing it correctly.
  const existingTax = isEdit ? invoiceQuery.data?.tax : null
  const activeTaxOptions = (taxesQuery.data ?? []).filter((tax) => tax.is_active)
  const taxOptions: { id: string; code: string; name: string; type: string; rate: string | number }[] =
    existingTax && !activeTaxOptions.some((tax) => tax.id === existingTax.id) ? [...activeTaxOptions, existingTax] : activeTaxOptions

  const form = useForm<InvoiceEditorValues>({
    resolver: zodResolver(invoiceFormSchema),
    defaultValues: emptyInvoiceEditorValues,
  })

  useEffect(() => {
    const invoice = invoiceQuery.data
    if (!invoice) return

    if (invoice.status !== 'draft') {
      toast.error('Only draft invoices can be edited.')
      navigate(`/sales/invoices/${invoice.id}`, { replace: true })
      return
    }

    form.reset({
      invoice_date: invoice.invoice_date,
      due_date: invoice.due_date,
      discount_type: invoice.discount_type ?? 'amount',
      discount_amount: String(invoice.discount_amount),
      discount_percentage: invoice.discount_percentage != null ? String(invoice.discount_percentage) : '',
      tax_id: invoice.tax_id ?? '',
      remarks: invoice.remarks ?? '',
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [invoiceQuery.data])

  const toPayload = (values: InvoiceEditorValues): InvoiceFormValues => ({
    delivery_id: selectedDeliveryId ?? '',
    // Immutable once created (see invoiceFormSchema.ts) — only sent on create; UpdateInvoiceRequest
    // doesn't accept it, so omitting it here on edit is what the backend already expects.
    ...(isEdit ? {} : { invoice_type: selectedInvoiceType ?? undefined }),
    invoice_date: values.invoice_date,
    due_date: values.due_date,
    discount_type: values.discount_type,
    // Only the field matching discount_type carries real data — InvoiceService::resolveDiscount()
    // on the backend derives discount_amount from discount_percentage itself in Percentage mode.
    discount_amount: values.discount_type === 'amount' ? (values.discount_amount === '' ? 0 : Number(values.discount_amount)) : null,
    discount_percentage: values.discount_type === 'percentage' ? (values.discount_percentage === '' ? 0 : Number(values.discount_percentage)) : null,
    // TaxService::calculate() computes tax_amount server-side from tax_id — never sent directly
    // from here. See docs/TAX_ENGINE_DESIGN.md §6.
    tax_id: values.tax_id || null,
    tax_amount: null,
    remarks: values.remarks || null,
  })

  const saveMutation = useMutation({
    mutationFn: (values: InvoiceEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateInvoice(id!, payload) : createInvoice(payload)
    },
    onSuccess: (invoice) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast.success(isEdit ? 'Invoice updated.' : 'Invoice saved as draft.')
      if (!isEdit) {
        navigate(`/sales/invoices/${invoice.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitInvoice(id!),
    onSuccess: (invoice) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Invoice submitted — Accounts Receivable created.')
      navigate(`/sales/invoices/${invoice.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const watchedDiscountType = form.watch('discount_type')
  const watchedDiscountAmount = form.watch('discount_amount')
  const watchedDiscountPercentage = form.watch('discount_percentage')
  const watchedTaxId = form.watch('tax_id')
  const selectedTax = taxOptions.find((tax) => tax.id === watchedTaxId) ?? null

  const previewLines: PreviewLine[] = isEdit
    ? (invoiceQuery.data?.items ?? []).map((line) => ({ ...line }))
    : (selectedDelivery?.items ?? []).map((line) => ({ ...line }))
  const subtotal = previewLines.reduce((sum, line) => sum + Number(line.amount), 0)
  // Preview only — InvoiceService::resolveDiscount() on the backend is the authoritative
  // computation on save; this mirrors that same formula purely for instant visual feedback.
  const discountAmount =
    watchedDiscountType === 'percentage'
      ? Math.round(subtotal * (Number(watchedDiscountPercentage || 0) / 100) * 100) / 100
      : Number(watchedDiscountAmount || 0)
  // Preview only — TaxService::calculate() on the backend always computes and returns the
  // authoritative tax_amount on save (Exclusive mode, this document's only mode today). This
  // mirrors that same formula purely for instant visual feedback before the round trip; the
  // saved value never comes from here. See docs/TAX_ENGINE_DESIGN.md §4/§6.
  const watchedTax = selectedTax && selectedTax.type === 'vat' ? Math.round(subtotal * (Number(selectedTax.rate) / 100) * 100) / 100 : 0
  const grandTotal = subtotal - discountAmount + watchedTax

  if (isEdit && invoiceQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): pick the Invoice Type (which Naming Series numbers it) and
  // the Delivery this invoice originates from — both fixed for the invoice's lifetime.
  if (!isEdit && (!selectedDeliveryId || !selectedInvoiceType)) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Invoice" description="Every Invoice originates from exactly one delivered, not-yet-invoiced Delivery." />
        <Card>
          <CardHeader>
            <CardTitle>Select Invoice Type &amp; Delivery</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Invoice Type</label>
              <Select value={selectedInvoiceType ?? ''} onValueChange={(value) => setSelectedInvoiceType(value as InvoiceType)}>
                <SelectTrigger className="w-full sm:w-96">
                  <SelectValue placeholder="Select invoice type" />
                </SelectTrigger>
                <SelectContent>
                  {INVOICE_TYPE_OPTIONS.map(([value, label]) => (
                    <SelectItem key={value} value={value}>
                      {label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-sm text-muted-foreground">Determines which Naming Series generates the document number — cannot be changed afterward.</p>
            </div>
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Delivery</label>
              <Select value={selectedDeliveryId ?? ''} onValueChange={setSelectedDeliveryId} disabled={eligibleDeliveriesQuery.isLoading}>
                <SelectTrigger className="w-full sm:w-96">
                  <SelectValue
                    placeholder={
                      eligibleDeliveriesQuery.isLoading
                        ? 'Loading…'
                        : eligibleDeliveries.length === 0
                          ? 'No deliveries available to invoice'
                          : 'Select delivery'
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {eligibleDeliveries.map((delivery) => (
                    <SelectItem key={delivery.id} value={delivery.id}>
                      {delivery.document_number} — {delivery.customer?.customer_name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-sm text-muted-foreground">Only delivered orders that have not already been invoiced are shown.</p>
            </div>
            <Button type="button" variant="outline" className="self-start" onClick={() => navigate('/sales/invoices')}>
              Cancel
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const onSubmit = form.handleSubmit((values) => {
    if (values.discount_type === 'amount' && Number(values.discount_amount || 0) > subtotal) {
      form.setError('discount_amount', { message: 'Cannot exceed subtotal' })
      return
    }
    if (values.discount_type === 'percentage' && Number(values.discount_percentage || 0) > 100) {
      form.setError('discount_percentage', { message: 'Cannot exceed 100%' })
      return
    }
    saveMutation.mutate(values)
  })

  const delivery = isEdit ? invoiceQuery.data?.delivery : selectedDelivery
  const customerName = isEdit ? invoiceQuery.data?.customer?.customer_name : selectedDelivery?.customer?.customer_name
  const invoiceType = isEdit ? invoiceQuery.data?.invoice_type : selectedInvoiceType

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${invoiceQuery.data?.document_number ?? 'Invoice'}` : 'New Invoice'}
        description={`Invoicing ${delivery?.document_number ?? ''} — ${customerName ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Invoice Details</CardTitle>
              <StatusBadge status={isEdit ? (invoiceQuery.data?.display_status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Delivery</span>
                <span className="text-sm font-medium">
                  {delivery?.document_number} — {customerName}
                </span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-xs text-muted-foreground">Invoice Type</span>
                <span className="text-sm font-medium">{invoiceType ? INVOICE_TYPE_LABELS[invoiceType] : '—'}</span>
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
                name="discount_type"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Discount Type</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="amount">Amount (Rp)</SelectItem>
                        <SelectItem value="percentage">Percentage (%)</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {watchedDiscountType === 'percentage' ? (
                <FormField
                  control={form.control}
                  name="discount_percentage"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Discount (%)</FormLabel>
                      <FormControl>
                        <div className="relative">
                          <Input type="number" min="0" max="100" step="0.01" placeholder="0" className="pr-9" {...field} />
                          <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">%</span>
                        </div>
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              ) : (
                <FormField
                  control={form.control}
                  name="discount_amount"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Discount (Rp)</FormLabel>
                      <FormControl>
                        <RupiahInput value={field.value} onChange={field.onChange} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
              <FormField
                control={form.control}
                name="tax_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tax</FormLabel>
                    <Select value={field.value || NO_TAX} onValueChange={(next) => field.onChange(next === NO_TAX ? '' : next)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={taxesQuery.isLoading ? 'Loading…' : 'No tax'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_TAX}>No tax</SelectItem>
                        {taxOptions.map((tax) => (
                          <SelectItem key={tax.id} value={tax.id}>
                            {tax.name} ({tax.code}){tax.type === 'vat' ? ` — ${tax.rate}%` : ''}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
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
              <DataTable
                columns={lineColumns}
                data={previewLines}
                rowKey={(row) => row.id}
                emptyMessage="No line items."
              />
              <p className="mt-2 text-sm text-muted-foreground">
                Items are copied from the Delivery and cannot be changed here — cancel and re-invoice if the Delivery was wrong.
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
                <span className="text-muted-foreground">{discountLabel(watchedDiscountType, watchedDiscountPercentage)}</span>
                <span>-{formatCurrency(discountAmount)}</span>
              </div>
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">Tax</span>
                <span>{formatCurrency(watchedTax)}</span>
              </div>
              <Separator className="w-full max-w-64" />
              <div className="flex w-full max-w-64 justify-between text-base font-semibold">
                <span>Grand Total</span>
                <span>{formatCurrency(grandTotal)}</span>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/sales/invoices')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && invoiceQuery.data?.status === 'draft' && (
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
