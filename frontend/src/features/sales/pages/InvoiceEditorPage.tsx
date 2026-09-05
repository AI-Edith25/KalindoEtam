import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Plus, Save, Send, Trash2 } from 'lucide-react'
import type { NavigateFunction } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchBranches, fetchCustomersLookup, fetchSalesPersonsLookup, fetchTaxesLookup, fetchTermsOfPaymentLookup } from '@/features/master/api/lookupsApi'
import { addDays } from '@/shared/lib/dateMath'
import { computeSubtotal, lineAmount, lineTaxAmount } from '@/shared/lib/documentTotals'
import { fetchDeliveries } from '../api/deliveryApi'
import { createInvoice, fetchInvoice, submitInvoice, updateInvoice } from '../api/invoiceApi'
import { emptyInvoiceEditorValues, invoiceFormSchema, type InvoiceEditorValues } from '../lib/invoiceFormSchema'
import { INVOICE_TYPE_LABELS, INVOICE_TYPE_OPTIONS } from '../lib/invoiceTypeLabels'
import { discountLabel } from '../lib/discount'
import type { Delivery, Invoice, InvoiceFormValues, InvoiceType } from '../types'

const NO_TAX = '__none__'
const NO_TOP = '__none__'
const NO_SALES_PERSON = '__none__'

interface PreviewLine {
  id: string
  item_code: string | null
  item_name: string
  uom: string | null
  qty: number
  rate: string | number
  amount: string | number
  // Already resolved server-side (from the source Delivery/Sales Order line) — never
  // recomputed here, unlike Transportation's own header-level tax preview below.
  tax: { id: string; code: string; name: string; type: string; rate: string | number } | null
  tax_amount: string | number
}

/** Transportation-only manual line — no Item/inventory link, matching Debit Note's own freestanding-line pattern (a plain useState array, not RHF/zod). */
interface TransportLine {
  key: string
  description: string
  qty: string
  rate: string
}

let transportLineCounter = 0
const nextTransportLineKey = () => `transport-${++transportLineCounter}`
const emptyTransportLine = (): TransportLine => ({ key: nextTransportLineKey(), description: '', qty: '1', rate: '0' })

const lineColumns: DataTableColumn<PreviewLine>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
  { header: 'Tax', accessor: (row) => row.tax?.name ?? '—' },
  { header: 'Tax Amount', accessor: (row) => formatCurrency(row.tax_amount), className: 'text-right' },
]

export function InvoiceEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selectedDeliveryIds, setSelectedDeliveryIds] = useState<Set<string>>(new Set())
  const [selectedInvoiceType, setSelectedInvoiceType] = useState<InvoiceType | null>(null)
  // Checking boxes must not auto-advance past the selection screen — with multi-select, the
  // user needs to be able to tick a second/third Delivery before moving on. An explicit
  // Continue click is what commits the selection and mounts InvoiceForm.
  const [selectionConfirmed, setSelectionConfirmed] = useState(false)

  const toggleDelivery = (deliveryId: string, checked: boolean) => {
    setSelectedDeliveryIds((prev) => {
      const next = new Set(prev)
      if (checked) next.add(deliveryId)
      else next.delete(deliveryId)
      return next
    })
  }

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
    enabled: isEdit,
  })

  // Eligible = complete and not yet invoiced. Fetched only in create mode, before any Delivery is picked.
  const eligibleDeliveriesQuery = useQuery({
    queryKey: ['deliveries-eligible-for-invoice'],
    queryFn: () => fetchDeliveries({ page: 1, per_page: 100, status: 'complete' }),
    enabled: !isEdit,
  })
  const eligibleDeliveries = (eligibleDeliveriesQuery.data?.data ?? []).filter((delivery) => !delivery.is_invoiced)
  const selectedDeliveries = eligibleDeliveries.filter((delivery) => selectedDeliveryIds.has(delivery.id))
  // Once ≥1 Delivery is checked, only Deliveries from the same Customer remain selectable.
  const selectedCustomerId = selectedDeliveries[0]?.customer_id ?? null
  const selectableDeliveries = selectedCustomerId
    ? eligibleDeliveries.filter((delivery) => delivery.customer_id === selectedCustomerId)
    : eligibleDeliveries

  useEffect(() => {
    const invoice = invoiceQuery.data
    if (!invoice) return

    if (invoice.status !== 'draft') {
      toast.error('Only draft invoices can be edited.')
      navigate(`/sales/invoices/${invoice.id}`, { replace: true })
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

  // Step 1 (create mode only): pick the Invoice Type (which Naming Series numbers it) and
  // one or more Deliveries this invoice originates from — both fixed for the invoice's lifetime.
  if (!isEdit && !selectionConfirmed) {
    const allSelectableChecked = selectableDeliveries.length > 0 && selectableDeliveries.every((delivery) => selectedDeliveryIds.has(delivery.id))
    const isTransportation = selectedInvoiceType === 'transportation'

    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="New Invoice"
          description={
            isTransportation
              ? 'Transportation Invoices are billed directly to a Customer — no Sales Order or Delivery required.'
              : 'An Invoice can combine one or more delivered, not-yet-invoiced Deliveries from the same Customer.'
          }
        />
        <Card>
          <CardHeader>
            <CardTitle>{isTransportation ? 'Select Invoice Type' : 'Select Invoice Type & Delivery'}</CardTitle>
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
            {!isTransportation && (
              <div className="flex flex-col gap-1.5">
                <label className="text-sm font-medium">Delivery</label>
                {eligibleDeliveriesQuery.isLoading ? (
                  <div className="flex items-center justify-center py-8">
                    <Loader2 className="size-6 animate-spin text-muted-foreground" />
                  </div>
                ) : selectableDeliveries.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No deliveries available to invoice.</p>
                ) : (
                  <div className="overflow-x-auto rounded-md border">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead className="w-10">
                            <Checkbox
                              checked={allSelectableChecked}
                              onCheckedChange={(checked) => selectableDeliveries.forEach((delivery) => toggleDelivery(delivery.id, checked === true))}
                              aria-label="Select all eligible deliveries"
                            />
                          </TableHead>
                          <TableHead>Document Number</TableHead>
                          <TableHead>Customer</TableHead>
                          <TableHead>Delivery Date</TableHead>
                          <TableHead className="text-right">Items</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {selectableDeliveries.map((delivery) => {
                          const checked = selectedDeliveryIds.has(delivery.id)
                          return (
                            <TableRow key={delivery.id} data-state={checked ? 'selected' : undefined}>
                              <TableCell>
                                <Checkbox
                                  checked={checked}
                                  onCheckedChange={(value) => toggleDelivery(delivery.id, value === true)}
                                  aria-label={`Select ${delivery.document_number}`}
                                />
                              </TableCell>
                              <TableCell className="font-medium">{delivery.document_number}</TableCell>
                              <TableCell>{delivery.customer?.customer_name}</TableCell>
                              <TableCell>{delivery.delivery_date}</TableCell>
                              <TableCell className="text-right">{delivery.items.length}</TableCell>
                            </TableRow>
                          )
                        })}
                      </TableBody>
                    </Table>
                  </div>
                )}
                <p className="text-sm text-muted-foreground">
                  Only delivered orders that have not already been invoiced are shown — once you select one, only Deliveries from the same Customer remain
                  selectable.
                </p>
              </div>
            )}
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => navigate('/sales/invoices')}>
                Cancel
              </Button>
              <Button
                type="button"
                disabled={isTransportation ? !selectedInvoiceType : selectedDeliveryIds.size === 0 || !selectedInvoiceType}
                onClick={() => setSelectionConfirmed(true)}
              >
                Continue
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  // In edit mode, invoiceQuery.data is already guaranteed loaded here (the isLoading gate
  // above covers it) — this is just the type-narrowing companion to it.
  if (isEdit && !invoiceQuery.data) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <InvoiceForm
      key={isEdit ? id : Array.from(selectedDeliveryIds).sort().join(',')}
      isEdit={isEdit}
      id={id}
      invoice={invoiceQuery.data}
      selectedDeliveries={selectedDeliveries}
      selectedInvoiceType={selectedInvoiceType}
      navigate={navigate}
      queryClient={queryClient}
    />
  )
}

/** Same fallback tier the single-Delivery flow already used (Delivery's own TOP, then the Customer's default when unusable) — extended so "the selected Deliveries disagree on Terms of Payment" also falls through to the Customer's default, rather than silently picking one Delivery's value. */
function resolveTermsOfPaymentDefault(deliveries: Delivery[]): string {
  const uniqueTop = new Set(deliveries.map((delivery) => delivery.terms_of_payment_id ?? null))
  const sharedTop = uniqueTop.size === 1 ? [...uniqueTop][0] : null
  return sharedTop ?? deliveries[0]?.customer?.terms_of_payment_id ?? ''
}

/**
 * Only mounts once its source data has already loaded (the existing Invoice in edit mode,
 * or the selected Delivery in create mode) — so useForm's defaultValues can be computed
 * directly from real data on first render. An earlier version populated Terms of Payment's
 * default via a separate setValue() effect firing after the Delivery was picked; verified
 * live on production, it never actually applied (same root cause diagnosed and fixed on the
 * Delivery editor: a race with react-hook-form's own field registration). Mounting fresh
 * with the right defaultValues from the start sidesteps that class of bug entirely.
 */
function InvoiceForm({
  isEdit,
  id,
  invoice,
  selectedDeliveries,
  selectedInvoiceType,
  navigate,
  queryClient,
}: {
  isEdit: boolean
  id: string | undefined
  invoice: Invoice | undefined
  selectedDeliveries: Delivery[]
  selectedInvoiceType: InvoiceType | null
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const isTransportation = (isEdit ? invoice?.invoice_type : selectedInvoiceType) === 'transportation'

  // Transportation only — picked directly here instead of being derived from a Delivery.
  const customersQuery = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup, enabled: !isEdit && isTransportation })
  const [selectedCustomerId, setSelectedCustomerId] = useState('')
  // Transportation only — no Sales Order to derive Branch from, so it's captured directly here
  // at create time. Read-only thereafter; corrections go through the Invoice Detail page's own
  // "Edit Branch" dialog (works regardless of Draft/Submitted status), not this create-only field.
  const branchesQuery = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches, enabled: !isEdit && isTransportation })
  const [selectedBranchId, setSelectedBranchId] = useState('')

  // New Transportation invoice only — default to the head-office branch, same convention as
  // SalesOrderEditorPage's own branch_id default (single-branch companies never need to touch this).
  useEffect(() => {
    if (isEdit || !isTransportation || !branchesQuery.data?.length || selectedBranchId) return

    const headOffice = branchesQuery.data.find((branch) => branch.is_head_office) ?? branchesQuery.data[0]
    setSelectedBranchId(headOffice.id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchesQuery.data, isEdit, isTransportation])
  const [transportLines, setTransportLines] = useState<TransportLine[]>(() => [emptyTransportLine()])
  const addTransportLine = () => setTransportLines((prev) => [...prev, emptyTransportLine()])
  const removeTransportLine = (key: string) => setTransportLines((prev) => prev.filter((line) => line.key !== key))
  const setTransportLine = (key: string, patch: Partial<TransportLine>) =>
    setTransportLines((prev) => prev.map((line) => (line.key === key ? { ...line, ...patch } : line)))

  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  const termsOfPayment = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })
  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  // Only Active taxes may be selected for a new/changed assignment (docs/TAX_ENGINE_DESIGN.md §9)
  // — but an invoice already referencing a since-deactivated tax must keep showing it correctly.
  const existingTax = isEdit ? invoice?.tax : null
  const activeTaxOptions = (taxesQuery.data ?? []).filter((tax) => tax.is_active && tax.transaction_type === 'sales')
  const taxOptions: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string }[] =
    existingTax && !activeTaxOptions.some((tax) => tax.id === existingTax.id) ? [...activeTaxOptions, existingTax] : activeTaxOptions

  const form = useForm<InvoiceEditorValues>({
    resolver: zodResolver(invoiceFormSchema),
    defaultValues: invoice
      ? {
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          terms_of_payment_id: invoice.terms_of_payment_id ?? '',
          discount_type: invoice.discount_type ?? 'amount',
          discount_amount: String(invoice.discount_amount),
          discount_percentage: invoice.discount_percentage != null ? String(invoice.discount_percentage) : '',
          tax_id: invoice.tax_id ?? '',
          remarks: invoice.remarks ?? '',
          sales_person_id: invoice.sales_person_id ?? '',
          reference_1: invoice.reference_1 ?? '',
          reference_2: invoice.reference_2 ?? '',
        }
      : {
          ...emptyInvoiceEditorValues,
          // Inherits whatever Terms of Payment the selected Deliveries agree on (a deliberate
          // override, not just the Customer's default re-derived again), falling back to the
          // Customer's own default when they disagree or have none. Still freely changeable —
          // this is only the starting value. See resolveTermsOfPaymentDefault().
          terms_of_payment_id: resolveTermsOfPaymentDefault(selectedDeliveries),
          // Sales Person and Reference 1 (Goods) are auto-filled server-side from the Sales
          // Order at save time (InvoiceService::createGoods()) - same "assigned when saved"
          // treatment as the invoice number, editable here once the invoice exists.
        },
  })

  const watchedTopId = form.watch('terms_of_payment_id')
  const watchedInvoiceDate = form.watch('invoice_date')

  // Recomputes Due Date whenever the Terms of Payment or Invoice Date changes — Due Date
  // itself is never a dependency here, so a manual edit to it holds until the user touches
  // one of these two inputs again. Seeded with the mounted values so the *first* time the
  // Terms of Payment lookup finishes loading (a data-arrival dependency change, not a user
  // change) doesn't read as "changed" and silently stomp a saved/manually-set Due Date.
  const lastRecomputedForRef = useRef({ topId: watchedTopId, invoiceDate: watchedInvoiceDate })
  useEffect(() => {
    if (!watchedTopId || !watchedInvoiceDate) return

    const top = termsOfPayment.data?.find((t) => t.id === watchedTopId)
    if (!top) return

    const last = lastRecomputedForRef.current
    if (last.topId === watchedTopId && last.invoiceDate === watchedInvoiceDate) return
    lastRecomputedForRef.current = { topId: watchedTopId, invoiceDate: watchedInvoiceDate }

    form.setValue('due_date', addDays(watchedInvoiceDate, top.days), { shouldValidate: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [watchedTopId, watchedInvoiceDate, termsOfPayment.data])

  const toPayload = (values: InvoiceEditorValues): InvoiceFormValues => ({
    // Goods sends delivery_ids; Transportation sends customer_id + items instead — delivery_ids
    // must be omitted entirely for Transportation (not []), since StoreInvoiceRequest's array/
    // min:1 sub-rules only skip on null/absent, not on an empty array.
    ...(isTransportation
      ? {
          customer_id: selectedCustomerId,
          items: transportLines
            .filter((line) => line.description.trim() !== '')
            .map((line) => ({ description: line.description.trim(), qty: Number(line.qty) || 0, rate: Number(line.rate) || 0 })),
        }
      : { delivery_ids: selectedDeliveries.map((delivery) => delivery.id) }),
    // Immutable once created (see invoiceFormSchema.ts) — only sent on create; UpdateInvoiceRequest
    // doesn't accept it, so omitting it here on edit is what the backend already expects.
    ...(isEdit ? {} : { invoice_type: selectedInvoiceType ?? undefined }),
    // Transportation, create-only — same posture as invoice_type above. Post-submit corrections
    // go through Invoice Detail's dedicated "Edit Branch" action, not this form.
    ...(isTransportation && !isEdit ? { branch_id: selectedBranchId } : {}),
    invoice_date: values.invoice_date,
    due_date: values.due_date,
    terms_of_payment_id: values.terms_of_payment_id || null,
    discount_type: values.discount_type,
    // Only the field matching discount_type carries real data — InvoiceService::resolveDiscount()
    // on the backend derives discount_amount from discount_percentage itself in Percentage mode.
    discount_amount: values.discount_type === 'amount' ? (values.discount_amount === '' ? 0 : Number(values.discount_amount)) : null,
    discount_percentage: values.discount_type === 'percentage' ? (values.discount_percentage === '' ? 0 : Number(values.discount_percentage)) : null,
    // TaxService::calculate() computes tax_amount server-side from tax_id — never sent directly
    // from here. See docs/TAX_ENGINE_DESIGN.md §6. Goods invoices have no header tax at all
    // anymore (tax is per-line, resolved when the Sales Order/Delivery line was created) — omitted
    // entirely so the backend's own null default applies.
    tax_id: isTransportation ? values.tax_id || null : undefined,
    tax_amount: null,
    remarks: values.remarks || null,
    sales_person_id: values.sales_person_id || null,
    reference_1: values.reference_1 || null,
    reference_2: values.reference_2 || null,
  })

  const saveMutation = useMutation({
    mutationFn: (values: InvoiceEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateInvoice(id!, payload) : createInvoice(payload)
    },
    onSuccess: (savedInvoice) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast.success(isEdit ? 'Invoice updated.' : 'Invoice saved as draft.')
      if (!isEdit) {
        navigate(`/sales/invoices/${savedInvoice.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitInvoice(id!),
    onSuccess: (submittedInvoice) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Invoice submitted — Accounts Receivable created.')
      navigate(`/sales/invoices/${submittedInvoice.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const watchedDiscountType = form.watch('discount_type')
  const watchedDiscountAmount = form.watch('discount_amount')
  const watchedDiscountPercentage = form.watch('discount_percentage')
  const watchedTaxId = form.watch('tax_id')
  // Transportation (no Item-backed lines) keeps the independent header Select, driven by the
  // RHF field. Goods invoices have no single header tax anymore — each line already carries
  // its own resolved tax (inherited from its Sales Order/Delivery line), so the total below is
  // always a sum of the lines, never a single Select's calculation.
  const selectedTax = isTransportation ? (taxOptions.find((tax) => tax.id === watchedTaxId) ?? null) : null

  const previewLines: PreviewLine[] = isEdit
    ? (invoice?.items ?? []).map((line) => ({ ...line }))
    : selectedDeliveries.flatMap((delivery) => delivery.items.map((line) => ({ ...line })))
  const subtotal = !isEdit && isTransportation ? computeSubtotal(transportLines) : previewLines.reduce((sum, line) => sum + Number(line.amount), 0)
  // Preview only — InvoiceService::resolveDiscount() on the backend is the authoritative
  // computation on save; this mirrors that same formula purely for instant visual feedback.
  const discountAmount =
    watchedDiscountType === 'percentage'
      ? Math.round(subtotal * (Number(watchedDiscountPercentage || 0) / 100) * 100) / 100
      : Number(watchedDiscountAmount || 0)
  // Transportation: preview only, mirrors TaxService::calculate()'s Exclusive/Inclusive
  // formula (docs/TAX_ENGINE_DESIGN.md §4) purely for instant feedback before the round trip.
  // Goods: each line's tax_amount is already server-resolved (from the Delivery/Sales Order
  // line), so this is a real sum, not a preview.
  const watchedTax = isTransportation
    ? lineTaxAmount(subtotal, selectedTax)
    : previewLines.reduce((sum, line) => sum + Number(line.tax_amount || 0), 0)
  const grandTotal = subtotal - discountAmount + watchedTax

  const onSubmit = form.handleSubmit((values) => {
    if (!isEdit && isTransportation) {
      if (!selectedCustomerId) {
        toast.error('Select a Customer.')
        return
      }

      const validLines = transportLines.filter((line) => line.description.trim() !== '')
      if (validLines.length === 0) {
        toast.error('Add at least one line item.')
        return
      }
      if (validLines.some((line) => Number(line.qty) <= 0 || Number(line.rate) < 0)) {
        toast.error('Each line needs a qty greater than 0 and a rate of 0 or more.')
        return
      }
    }

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

  const deliveryLabel = isEdit
    ? invoice?.deliveries?.map((d) => d.document_number).join(', ')
    : selectedDeliveries.map((delivery) => delivery.document_number).join(', ')
  const customerName = isEdit
    ? invoice?.customer?.customer_name
    : isTransportation
      ? customersQuery.data?.find((customer) => customer.id === selectedCustomerId)?.customer_name
      : selectedDeliveries[0]?.customer?.customer_name
  const invoiceType = isEdit ? invoice?.invoice_type : selectedInvoiceType

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${invoice?.document_number ?? 'Invoice'}` : 'New Invoice'}
        description={isTransportation ? `Invoicing ${customerName ?? ''}.` : `Invoicing ${deliveryLabel ?? ''} — ${customerName ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Invoice Details</CardTitle>
              <StatusBadge status={isEdit ? (invoice?.display_status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {isTransportation ? (
                <div className="flex flex-col gap-1.5 sm:col-span-2">
                  <label className="text-sm font-medium">Customer</label>
                  {isEdit ? (
                    <span className="text-sm font-medium">{customerName ?? '—'}</span>
                  ) : (
                    <Select value={selectedCustomerId} onValueChange={setSelectedCustomerId}>
                      <SelectTrigger className="w-full sm:w-96">
                        <SelectValue placeholder={customersQuery.isLoading ? 'Loading…' : 'Select customer'} />
                      </SelectTrigger>
                      <SelectContent>
                        {customersQuery.data?.map((customer) => (
                          <SelectItem key={customer.id} value={customer.id}>
                            {customer.customer_name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </div>
              ) : (
                <div className="flex flex-col gap-0.5 sm:col-span-2">
                  <span className="text-xs text-muted-foreground">Delivery</span>
                  <span className="text-sm font-medium">
                    {deliveryLabel} — {customerName}
                  </span>
                </div>
              )}
              {isTransportation && (
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">Branch</label>
                  {isEdit ? (
                    <span className="text-sm font-medium">{invoice?.branch?.name ?? '—'}</span>
                  ) : (
                    <Select value={selectedBranchId} onValueChange={setSelectedBranchId}>
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder={branchesQuery.isLoading ? 'Loading…' : 'Select branch'} />
                      </SelectTrigger>
                      <SelectContent>
                        {branchesQuery.data?.map((branch) => (
                          <SelectItem key={branch.id} value={branch.id}>
                            {branch.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </div>
              )}
              <div className="flex flex-col gap-0.5">
                <span className="text-xs text-muted-foreground">Invoice Number</span>
                <span className="text-sm font-medium">{isEdit ? (invoice?.document_number ?? '—') : 'Assigned when saved'}</span>
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
                name="terms_of_payment_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Terms of Payment</FormLabel>
                    <Select value={field.value || NO_TOP} onValueChange={(value) => field.onChange(value === NO_TOP ? '' : value)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={termsOfPayment.isLoading ? 'Loading…' : 'None'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_TOP}>None</SelectItem>
                        {termsOfPayment.data?.map((top) => (
                          <SelectItem key={top.id} value={top.id}>
                            {top.name} ({top.code})
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
                name="sales_person_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Sales Person</FormLabel>
                    <Select value={field.value || NO_SALES_PERSON} onValueChange={(value) => field.onChange(value === NO_SALES_PERSON ? '' : value)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={salesPersonsQuery.isLoading ? 'Loading…' : 'None'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_SALES_PERSON}>None</SelectItem>
                        {salesPersonsQuery.data?.map((salesPerson) => (
                          <SelectItem key={salesPerson.id} value={salesPerson.id}>
                            {salesPerson.name}
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
                name="reference_1"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reference 1</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reference_2"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reference 2</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
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
              {isTransportation ? (
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
              ) : (
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Tax</span>
                  <span className="text-sm text-muted-foreground">{formatCurrency(watchedTax)}</span>
                  <p className="text-xs text-muted-foreground">Calculated per line — see the Line Items table below.</p>
                </div>
              )}
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
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Line Items</CardTitle>
              {!isEdit && isTransportation && (
                <Button type="button" variant="outline" size="sm" onClick={addTransportLine}>
                  <Plus className="size-4" />
                  Add Row
                </Button>
              )}
            </CardHeader>
            <CardContent>
              {!isEdit && isTransportation ? (
                <div className="overflow-x-auto rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Description</TableHead>
                        <TableHead className="w-32 text-right">Qty</TableHead>
                        <TableHead className="w-40 text-right">Rate</TableHead>
                        <TableHead className="w-40 text-right">Amount</TableHead>
                        <TableHead className="w-12" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {transportLines.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={5} className="text-center text-sm text-muted-foreground">
                            No line items yet.
                          </TableCell>
                        </TableRow>
                      )}
                      {transportLines.map((line) => (
                        <TableRow key={line.key}>
                          <TableCell>
                            <Input
                              placeholder="e.g. Ongkos Angkut Semen 50kg"
                              value={line.description}
                              onChange={(event) => setTransportLine(line.key, { description: event.target.value })}
                            />
                          </TableCell>
                          <TableCell>
                            <Input
                              type="number"
                              min={1}
                              step="1"
                              className="text-right"
                              value={line.qty}
                              onChange={(event) => setTransportLine(line.key, { qty: event.target.value })}
                            />
                          </TableCell>
                          <TableCell>
                            <Input
                              type="number"
                              min={0}
                              step="0.01"
                              className="text-right"
                              value={line.rate}
                              onChange={(event) => setTransportLine(line.key, { rate: event.target.value })}
                            />
                          </TableCell>
                          <TableCell className="text-right">{formatCurrency(lineAmount(line))}</TableCell>
                          <TableCell>
                            <Button type="button" variant="ghost" size="icon" onClick={() => removeTransportLine(line.key)}>
                              <Trash2 className="size-4" />
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              ) : (
                <>
                  <DataTable
                    columns={lineColumns}
                    data={previewLines}
                    rowKey={(row) => row.id}
                    emptyMessage="No line items."
                  />
                  <p className="mt-2 text-sm text-muted-foreground">
                    {invoiceType === 'transportation'
                      ? 'Items cannot be changed after the invoice is created.'
                      : 'Items are copied from the Delivery and cannot be changed here — cancel and re-invoice if the Delivery was wrong.'}
                  </p>
                </>
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
