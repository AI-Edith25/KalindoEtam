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
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import {
  fetchBranches,
  fetchSalesPersonsLookup,
  fetchTaxesLookup,
  fetchTermsOfPaymentLookup,
  fetchWarehousesLookup,
  searchCustomersLookup,
  searchItemsLookup,
  searchMiscellaneousItemsLookup,
} from '@/features/master/api/lookupsApi'
import { addDays } from '@/shared/lib/dateMath'
import { computeLineTaxTotal, computeSubtotal, lineAmount, lineTaxAmount } from '@/shared/lib/documentTotals'
import type { Customer, Item, MiscellaneousItem } from '@/features/master/types'
import { fetchDeliveries } from '../api/deliveryApi'
import { createInvoice, fetchInvoice, submitInvoice, updateInvoice } from '../api/invoiceApi'
import { emptyInvoiceEditorValues, invoiceFormSchema, type InvoiceEditorValues } from '../lib/invoiceFormSchema'
import { InvoiceSubmittedEditPage } from './InvoiceSubmittedEditPage'
import { INVOICE_TYPE_LABELS } from '../lib/invoiceTypeLabels'
import { discountLabel } from '../lib/discount'
import type { Delivery, Invoice, InvoiceFormValues, InvoiceType } from '../types'

const NO_TAX = '__none__'

/**
 * Draft invoice line, editable (Qty/Rate/Tax only — item identity and Delivery/Sales Order
 * linkage stay locked, same shape as InvoiceSubmittedEditPage's own EditableLine for a
 * Submitted invoice). Reachable before submit now too, since this is the one place the cement
 * price (not yet set up as an Item's standard rate) gets corrected by hand.
 */
interface EditableInvoiceLine {
  id: string
  item_code: string | null
  item_name: string
  uom: string | null
  qty: string
  rate: string
  tax_id: string
}

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

/**
 * Transportation-only manual line — no Item/inventory link, matching Debit Note's own
 * freestanding-line pattern (a plain useState array, not RHF/zod). `misc_item_id` is
 * local-only (never sent to the backend, which only accepts description/qty/rate) — it
 * just lets the SearchableSelect show its selection without a search round-trip.
 */
interface TransportLine {
  key: string
  misc_item_id: string
  description: string
  // The picked MiscellaneousItem's own UOM — carried along read-only, same as DirectGoodsLine.uom.
  uom: string | null
  qty: string
  rate: string
}

let transportLineCounter = 0
const nextTransportLineKey = () => `transport-${++transportLineCounter}`
const emptyTransportLine = (): TransportLine => ({ key: nextTransportLineKey(), misc_item_id: '', description: '', uom: null, qty: '1', rate: '0' })

/**
 * Wizard-only, never persisted — the backend still only ever sees invoice_type 'goods' or
 * 'transportation' (see InvoiceFormValues.warehouse_id's own comment). This third value exists
 * purely to drive Step 1's branching (skip the Delivery picker, like Transportation) and which
 * Line Items UI InvoiceForm renders (a real Item Picker, unlike Transportation's free text).
 */
type WizardMode = InvoiceType | 'goods_direct'
const WIZARD_MODE_OPTIONS: [WizardMode, string][] = [
  ['goods', 'Goods'],
  ['goods_direct', 'Goods (Direct)'],
  ['transportation', 'Transportation'],
]

/**
 * Goods (Direct) line — real Item-backed (unlike TransportLine's free description), but still
 * local component state rather than RHF/zod, same posture as TransportLine: the backend only
 * accepts item_id/qty/rate/tax_id on create, never re-edited afterward.
 */
interface DirectGoodsLine {
  key: string
  item_id: string
  item_label: string
  uom: string | null
  qty: string
  rate: string
  tax_id: string
}

let directGoodsLineCounter = 0
const nextDirectGoodsLineKey = () => `direct-goods-${++directGoodsLineCounter}`
const emptyDirectGoodsLine = (): DirectGoodsLine => ({ key: nextDirectGoodsLineKey(), item_id: '', item_label: '', uom: null, qty: '1', rate: '0', tax_id: '' })

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
  const [selectedMode, setSelectedMode] = useState<WizardMode | null>(null)
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

    if (invoice.status !== 'draft' && invoice.status !== 'submitted') {
      toast.error('Cancelled invoices cannot be edited.')
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

  // Submitted routes to a separate, purpose-built editor (InvoiceSubmittedEditPage) — this
  // page's own form below is Draft-only and built around 3 create-time sub-flows (Goods/Direct/
  // Transportation), a different shape from "correct an existing Submitted invoice's numbers".
  // See InvoiceService::updateSubmitted().
  if (isEdit && invoiceQuery.data?.status === 'submitted') {
    return <InvoiceSubmittedEditPage />
  }

  // Step 1 (create mode only): pick the Invoice Type (which Naming Series numbers it) and
  // one or more Deliveries this invoice originates from — both fixed for the invoice's lifetime.
  if (!isEdit && !selectionConfirmed) {
    const allSelectableChecked = selectableDeliveries.length > 0 && selectableDeliveries.every((delivery) => selectedDeliveryIds.has(delivery.id))
    const isTransportation = selectedMode === 'transportation'
    const isDirectGoods = selectedMode === 'goods_direct'
    const skipDeliveryStep = isTransportation || isDirectGoods

    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="New Invoice"
          description={
            isTransportation
              ? 'Transportation Invoices are billed directly to a Customer — no Sales Order or Delivery required.'
              : isDirectGoods
                ? 'Goods (Direct) Invoices are billed directly to a Customer without a Sales Order or Delivery — stock will be reduced immediately upon saving.'
                : 'An Invoice can combine one or more delivered, not-yet-invoiced Deliveries from the same Customer.'
          }
        />
        <Card>
          <CardHeader>
            <CardTitle>{skipDeliveryStep ? 'Select Invoice Type' : 'Select Invoice Type & Delivery'}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Invoice Type</label>
              <Select value={selectedMode ?? ''} onValueChange={(value) => setSelectedMode(value as WizardMode)}>
                <SelectTrigger className="w-full sm:w-96">
                  <SelectValue placeholder="Select invoice type" />
                </SelectTrigger>
                <SelectContent>
                  {WIZARD_MODE_OPTIONS.map(([value, label]) => (
                    <SelectItem key={value} value={value}>
                      {label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-sm text-muted-foreground">Determines which Naming Series generates the document number — cannot be changed afterward.</p>
            </div>
            {!skipDeliveryStep && (
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
                disabled={skipDeliveryStep ? !selectedMode : selectedDeliveryIds.size === 0 || !selectedMode}
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
      // invoice_type stays 'goods' either way — isDirectGoodsCreate below is what actually
      // tells InvoiceForm which of the two 'goods' sub-flows this is.
      selectedInvoiceType={selectedMode === 'goods_direct' ? 'goods' : selectedMode}
      isDirectGoodsCreate={selectedMode === 'goods_direct'}
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

/** Printed/displayed Location default for a new Delivery-based Invoice — the anchor (first) Delivery's own warehouse. Still freely changeable, this is only the starting value. */
function resolveLocationWarehouseDefault(deliveries: Delivery[]): string {
  return deliveries[0]?.warehouse_id ?? ''
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
  isDirectGoodsCreate,
  navigate,
  queryClient,
}: {
  isEdit: boolean
  id: string | undefined
  invoice: Invoice | undefined
  selectedDeliveries: Delivery[]
  selectedInvoiceType: InvoiceType | null
  isDirectGoodsCreate: boolean
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const isTransportation = (isEdit ? invoice?.invoice_type : selectedInvoiceType) === 'transportation'
  // invoice_type is 'goods' for both Delivery-based and Direct Goods invoices — warehouse_id's
  // presence is what actually distinguishes them (see Invoice::isDirectGoods() on the backend).
  const isDirectGoods = isEdit ? !!invoice?.warehouse_id : isDirectGoodsCreate
  // Draft invoices (both Delivery-based and Direct Goods) reaching this form — Submitted ones
  // already route to InvoiceSubmittedEditPage — can have Qty/Rate/Tax corrected per line.
  // Transportation keeps its own freestanding add/remove line editor instead (TransportLine).
  const isEditableItemsMode = isEdit && !isTransportation
  const [editableLines, setEditableLines] = useState<EditableInvoiceLine[]>(() =>
    invoice
      ? invoice.items.map((line) => ({ id: line.id, item_code: line.item_code, item_name: line.item_name, uom: line.uom, qty: String(line.qty), rate: String(line.rate), tax_id: line.tax_id ?? '' }))
      : [],
  )
  const patchEditableLine = (lineId: string, patch: Partial<EditableInvoiceLine>) =>
    setEditableLines((prev) => prev.map((line) => (line.id === lineId ? { ...line, ...patch } : line)))

  // Transportation only — picked directly here instead of being derived from a Delivery.
  const [selectedCustomerId, setSelectedCustomerId] = useState('')
  // Holds the picked option's label (async SearchableSelect doesn't preload the full Customer
  // master, so a plain id can't display a label on its own).
  const [selectedCustomerOption, setSelectedCustomerOption] = useState<SearchableSelectOption<Customer> | undefined>(undefined)
  const loadCustomerOptions = async (query: string) => {
    const customers = await searchCustomersLookup(query)
    return customers.map((customer) => ({ value: customer.id, label: `${customer.customer_code} — ${customer.customer_name}`, data: customer }))
  }
  // Transportation and Goods (Direct) only — no Sales Order to derive Branch from, so it's
  // captured directly here at create time. Read-only thereafter; corrections go through the
  // Invoice Detail page's own "Edit Branch" dialog (works regardless of Draft/Submitted status
  // — Transportation only today), not this create-only field.
  const branchesQuery = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches, enabled: !isEdit && (isTransportation || isDirectGoods) })
  const branchOptions = branchesQuery.data?.map((branch) => ({ value: branch.id, label: branch.name })) ?? []
  const [selectedBranchId, setSelectedBranchId] = useState('')

  // New Transportation/Goods (Direct) invoice only — default to the head-office branch, same
  // convention as SalesOrderEditorPage's own branch_id default (single-branch companies never
  // need to touch this).
  useEffect(() => {
    if (isEdit || !(isTransportation || isDirectGoods) || !branchesQuery.data?.length || selectedBranchId) return

    const headOffice = branchesQuery.data.find((branch) => branch.is_head_office) ?? branchesQuery.data[0]
    setSelectedBranchId(headOffice.id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchesQuery.data, isEdit, isTransportation, isDirectGoods])
  const [transportLines, setTransportLines] = useState<TransportLine[]>(() => [emptyTransportLine()])
  const addTransportLine = () => setTransportLines((prev) => [...prev, emptyTransportLine()])
  const removeTransportLine = (key: string) => setTransportLines((prev) => prev.filter((line) => line.key !== key))
  const setTransportLine = (key: string, patch: Partial<TransportLine>) =>
    setTransportLines((prev) => prev.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  const loadMiscItemOptions = async (query: string) => {
    const miscItems = await searchMiscellaneousItemsLookup(query)
    return miscItems.map((item) => ({ value: item.id, label: item.description, data: item }))
  }

  // Goods (Direct) only — no Delivery to inherit a Location from, so it's a real required field
  // here (same Location field Delivery's own editor has), and it scopes the Item Picker's lookup.
  // Also doubles as that sub-flow's printed/displayed Location (see toPayload()) — Direct Goods
  // never shows the separate location_warehouse_id field below, one Location input is enough.
  const [directGoodsWarehouseId, setDirectGoodsWarehouseId] = useState('')
  // Always enabled now — every Goods flow (Delivery-based or Direct) can show/edit a Location.
  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: !isTransportation })
  const warehouseOptions = warehousesQuery.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []
  const [directGoodsLines, setDirectGoodsLines] = useState<DirectGoodsLine[]>(() => [emptyDirectGoodsLine()])
  const addDirectGoodsLine = () => setDirectGoodsLines((prev) => [...prev, emptyDirectGoodsLine()])
  const removeDirectGoodsLine = (key: string) => setDirectGoodsLines((prev) => prev.filter((line) => line.key !== key))
  const setDirectGoodsLine = (key: string, patch: Partial<DirectGoodsLine>) =>
    setDirectGoodsLines((prev) => prev.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  // Same Item Picker mechanics as SalesOrderLineItemTable's loadItemOptions/handleItemChange —
  // real Item master data (auto-filled UOM/rate/tax), unlike Transportation's description picker.
  const loadDirectGoodsItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query, directGoodsWarehouseId)
    return items.map((item) => ({ value: item.id, label: `${item.item_code} — ${item.item_name}`, data: item }))
  }
  const handleDirectGoodsItemChange = (key: string, itemId: string, option?: SearchableSelectOption<Item>) => {
    const selected = option?.data
    setDirectGoodsLine(key, {
      item_id: itemId,
      item_label: option?.label ?? '',
      uom: selected?.uom?.name ?? null,
      rate: selected ? String(selected.effective_rate) : '0',
      tax_id: selected?.sales_tax_id ?? '',
    })
  }

  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  const termsOfPayment = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })
  const termsOfPaymentOptions = termsOfPayment.data?.map((top) => ({ value: top.id, label: `${top.name} (${top.code})` })) ?? []
  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const salesPersonOptions = salesPersonsQuery.data?.map((salesPerson) => ({ value: salesPerson.id, label: salesPerson.name })) ?? []
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
          location_warehouse_id: invoice.location_warehouse_id ?? '',
        }
      : {
          ...emptyInvoiceEditorValues,
          // Inherits whatever Terms of Payment the selected Deliveries agree on (a deliberate
          // override, not just the Customer's default re-derived again), falling back to the
          // Customer's own default when they disagree or have none. Still freely changeable —
          // this is only the starting value. See resolveTermsOfPaymentDefault().
          terms_of_payment_id: resolveTermsOfPaymentDefault(selectedDeliveries),
          location_warehouse_id: resolveLocationWarehouseDefault(selectedDeliveries),
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
    // Delivery-based Goods sends delivery_ids; Transportation and Goods (Direct) send
    // customer_id + items instead — delivery_ids must be omitted entirely for those two (not
    // []), since StoreInvoiceRequest's array/min:1 sub-rules only skip on null/absent, not on an
    // empty array. Goods (Direct) also sends warehouse_id — the field the backend actually uses
    // to route to that sub-flow (invoice_type stays 'goods' either way).
    ...(isEdit
      ? // Items are only ever sent on edit when this draft's lines are actually editable
        // (isEditableItemsMode) — UpdateInvoiceRequest has no delivery_ids/customer_id/warehouse_id
        // fields at all, so those would just be silently dropped if sent.
        isEditableItemsMode
        ? { items: editableLines.map((line) => ({ id: line.id, qty: Number(line.qty) || 0, rate: Number(line.rate) || 0, tax_id: line.tax_id || null })) }
        : {}
      : isTransportation
        ? {
            customer_id: selectedCustomerId,
            items: transportLines
              .filter((line) => line.description.trim() !== '')
              .map((line) => ({
                description: line.description.trim(),
                qty: Number(line.qty) || 0,
                rate: Number(line.rate) || 0,
                uom: line.uom,
              })),
          }
        : isDirectGoods
          ? {
              customer_id: selectedCustomerId,
              warehouse_id: directGoodsWarehouseId,
              items: directGoodsLines
                .filter((line) => line.item_id !== '')
                .map((line) => ({ item_id: line.item_id, qty: Number(line.qty) || 0, rate: Number(line.rate) || 0, tax_id: line.tax_id || null })),
            }
          : { delivery_ids: selectedDeliveries.map((delivery) => delivery.id) }),
    // Immutable once created (see invoiceFormSchema.ts) — only sent on create; UpdateInvoiceRequest
    // doesn't accept it, so omitting it here on edit is what the backend already expects.
    ...(isEdit ? {} : { invoice_type: selectedInvoiceType ?? undefined }),
    // Transportation/Goods (Direct), create-only — same posture as invoice_type above. Post-submit
    // corrections go through Invoice Detail's dedicated "Edit Branch" action, not this form.
    ...((isTransportation || isDirectGoods) && !isEdit ? { branch_id: selectedBranchId } : {}),
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
    // Omitted for Direct Goods — that sub-flow's own Location field above (directGoodsWarehouseId)
    // already becomes warehouse_id, and the backend defaults location_warehouse_id from it
    // (InvoiceService::createDirectGoods()) when this key is absent.
    ...(isDirectGoods && !isEdit ? {} : { location_warehouse_id: values.location_warehouse_id || null }),
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
  const subtotal = !isEdit && isTransportation
    ? computeSubtotal(transportLines)
    : !isEdit && isDirectGoods
      ? computeSubtotal(directGoodsLines)
      : isEditableItemsMode
        ? computeSubtotal(editableLines)
        : previewLines.reduce((sum, line) => sum + Number(line.amount), 0)
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
    : !isEdit && isDirectGoods
      ? computeLineTaxTotal(directGoodsLines, (line) => taxOptions.find((tax) => tax.id === line.tax_id))
      : isEditableItemsMode
        ? computeLineTaxTotal(editableLines, (line) => taxOptions.find((tax) => tax.id === line.tax_id))
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

    if (!isEdit && isDirectGoods) {
      if (!selectedCustomerId) {
        toast.error('Select a Customer.')
        return
      }
      if (!directGoodsWarehouseId) {
        toast.error('Select a Location.')
        return
      }

      const validLines = directGoodsLines.filter((line) => line.item_id !== '')
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
    : isTransportation || isDirectGoods
      ? selectedCustomerOption?.data?.customer_name
      : selectedDeliveries[0]?.customer?.customer_name
  const invoiceType = isEdit ? invoice?.invoice_type : selectedInvoiceType

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${invoice?.document_number ?? 'Invoice'}` : 'New Invoice'}
        description={isTransportation || isDirectGoods ? `Invoicing ${customerName ?? ''}.` : `Invoicing ${deliveryLabel ?? ''} — ${customerName ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Invoice Details</CardTitle>
              <StatusBadge status={isEdit ? (invoice?.display_status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {isTransportation || isDirectGoods ? (
                <div className="flex flex-col gap-1.5 sm:col-span-2">
                  <label className="text-sm font-medium">Customer</label>
                  {isEdit ? (
                    <span className="text-sm font-medium">{customerName ?? '—'}</span>
                  ) : (
                    <SearchableSelect
                      className="w-full sm:w-96"
                      loadOptions={loadCustomerOptions}
                      selectedOption={selectedCustomerOption}
                      value={selectedCustomerId}
                      onChange={(value, option) => {
                        setSelectedCustomerId(value ?? '')
                        setSelectedCustomerOption(option)
                      }}
                      placeholder="Select customer"
                      aria-label="Customer"
                    />
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
              {(isTransportation || isDirectGoods) && (
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">Branch</label>
                  {isEdit ? (
                    <span className="text-sm font-medium">{invoice?.branch?.name ?? '—'}</span>
                  ) : (
                    <SearchableSelect
                      options={branchOptions}
                      value={selectedBranchId}
                      onChange={(value) => setSelectedBranchId(value ?? '')}
                      loading={branchesQuery.isLoading}
                      clearable={false}
                      placeholder="Select branch"
                      aria-label="Branch"
                    />
                  )}
                </div>
              )}
              {isDirectGoods && (
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">Location</label>
                  {isEdit ? (
                    <span className="text-sm font-medium">{invoice?.warehouse?.name ?? '—'}</span>
                  ) : (
                    <SearchableSelect
                      options={warehouseOptions}
                      value={directGoodsWarehouseId}
                      onChange={(value) => setDirectGoodsWarehouseId(value ?? '')}
                      loading={warehousesQuery.isLoading}
                      clearable={false}
                      placeholder="Select location"
                      aria-label="Location"
                    />
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
              {/* Delivery-based Goods only — Direct Goods' own Location above already is its
                  warehouse_id (one input is enough there); Transportation has no Location concept. */}
              {!isDirectGoods && !isTransportation && (
                <FormField
                  control={form.control}
                  name="location_warehouse_id"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Location</FormLabel>
                      <SearchableSelect
                        options={warehouseOptions}
                        value={field.value}
                        onChange={(value) => field.onChange(value ?? '')}
                        loading={warehousesQuery.isLoading}
                        placeholder="None"
                        aria-label="Location"
                      />
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
              <FormField
                control={form.control}
                name="terms_of_payment_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Terms of Payment</FormLabel>
                    <SearchableSelect
                      options={termsOfPaymentOptions}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={termsOfPayment.isLoading}
                      placeholder="None"
                      aria-label="Terms of Payment"
                    />
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
                    <SearchableSelect
                      options={salesPersonOptions}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={salesPersonsQuery.isLoading}
                      placeholder="None"
                      aria-label="Sales Person"
                    />
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
              {!isEdit && (isTransportation || isDirectGoods) && (
                <Button type="button" variant="outline" size="sm" onClick={isTransportation ? addTransportLine : addDirectGoodsLine}>
                  <Plus className="size-4" />
                  Add Row
                </Button>
              )}
            </CardHeader>
            <CardContent>
              {!isEdit && isTransportation ? (
                <LineItemTableScroll>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className={STICKY_FIRST_COL}>Description</TableHead>
                        <TableHead className="w-20">UOM</TableHead>
                        <TableHead className="w-32 text-right">Qty</TableHead>
                        <TableHead className="w-40 text-right">Rate</TableHead>
                        <TableHead className="w-40 text-right">Amount</TableHead>
                        <TableHead className="w-12" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {transportLines.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={6} className="text-center text-sm text-muted-foreground">
                            No line items yet.
                          </TableCell>
                        </TableRow>
                      )}
                      {transportLines.map((line) => (
                        <TableRow key={line.key}>
                          <TableCell className={STICKY_FIRST_COL}>
                            <SearchableSelect<MiscellaneousItem>
                              loadOptions={loadMiscItemOptions}
                              selectedOption={line.misc_item_id ? { value: line.misc_item_id, label: line.description } : undefined}
                              value={line.misc_item_id}
                              onChange={(value, option) =>
                                setTransportLine(line.key, {
                                  misc_item_id: value ?? '',
                                  description: option?.label ?? '',
                                  uom: option?.data?.uom?.name ?? null,
                                })
                              }
                              placeholder="Select description"
                              aria-label="Description"
                            />
                          </TableCell>
                          <TableCell className="text-sm text-muted-foreground">{line.uom ?? '—'}</TableCell>
                          <TableCell className="min-w-32">
                            <Input
                              type="number"
                              min={1}
                              step="1"
                              className="text-right"
                              value={line.qty}
                              onChange={(event) => setTransportLine(line.key, { qty: event.target.value })}
                            />
                          </TableCell>
                          <TableCell className="min-w-40">
                            <RupiahInput value={line.rate} onChange={(value) => setTransportLine(line.key, { rate: value })} />
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
                </LineItemTableScroll>
              ) : !isEdit && isDirectGoods ? (
                <LineItemTableScroll>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className={STICKY_FIRST_COL}>Item</TableHead>
                        <TableHead className="w-20">UOM</TableHead>
                        <TableHead className="w-32 text-right">Qty</TableHead>
                        <TableHead className="w-40 text-right">Rate</TableHead>
                        <TableHead className="w-48">Tax</TableHead>
                        <TableHead className="w-40 text-right">Amount</TableHead>
                        <TableHead className="w-12" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {directGoodsLines.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={7} className="text-center text-sm text-muted-foreground">
                            No line items yet.
                          </TableCell>
                        </TableRow>
                      )}
                      {directGoodsLines.map((line) => (
                        <TableRow key={line.key}>
                          <TableCell className={STICKY_FIRST_COL}>
                            <SearchableSelect<Item>
                              loadOptions={loadDirectGoodsItemOptions}
                              selectedOption={line.item_id ? { value: line.item_id, label: line.item_label } : undefined}
                              value={line.item_id}
                              onChange={(value, option) => handleDirectGoodsItemChange(line.key, value ?? '', option)}
                              placeholder="Select item"
                              aria-label="Item"
                            />
                          </TableCell>
                          <TableCell className="text-sm text-muted-foreground">{line.uom ?? '—'}</TableCell>
                          <TableCell className="min-w-32">
                            <Input
                              type="number"
                              min={1}
                              step="1"
                              className="text-right"
                              value={line.qty}
                              onChange={(event) => setDirectGoodsLine(line.key, { qty: event.target.value })}
                            />
                          </TableCell>
                          <TableCell className="min-w-40">
                            <RupiahInput value={line.rate} onChange={(value) => setDirectGoodsLine(line.key, { rate: value })} />
                          </TableCell>
                          <TableCell className="min-w-48">
                            <Select value={line.tax_id || NO_TAX} onValueChange={(next) => setDirectGoodsLine(line.key, { tax_id: next === NO_TAX ? '' : next })}>
                              <SelectTrigger className="w-full">
                                <SelectValue placeholder={taxesQuery.isLoading ? 'Loading…' : 'No tax'} />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value={NO_TAX}>No tax</SelectItem>
                                {taxOptions.map((tax) => (
                                  <SelectItem key={tax.id} value={tax.id}>
                                    {tax.name} ({tax.code}){tax.type === 'vat' ? ` — ${tax.rate}%` : ''}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </TableCell>
                          <TableCell className="text-right">{formatCurrency(lineAmount(line))}</TableCell>
                          <TableCell>
                            <Button type="button" variant="ghost" size="icon" onClick={() => removeDirectGoodsLine(line.key)}>
                              <Trash2 className="size-4" />
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </LineItemTableScroll>
              ) : isEditableItemsMode ? (
                <>
                  <LineItemTableScroll>
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead className={STICKY_FIRST_COL}>Item</TableHead>
                          <TableHead className="w-28 text-right">Qty</TableHead>
                          <TableHead className="w-40 text-right">Rate</TableHead>
                          <TableHead className="w-48">Tax</TableHead>
                          <TableHead className="w-36 text-right">Amount</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {editableLines.map((line) => (
                          <TableRow key={line.id}>
                            <TableCell className={STICKY_FIRST_COL}>
                              <div className="truncate font-medium">{line.item_code ?? '—'}</div>
                              <div className="truncate text-xs text-muted-foreground">
                                {line.item_name}
                                {line.uom ? ` · ${line.uom}` : ''}
                              </div>
                            </TableCell>
                            <TableCell className="min-w-28">
                              <Input
                                type="number"
                                min={1}
                                step="1"
                                className="text-right"
                                value={line.qty}
                                onChange={(event) => patchEditableLine(line.id, { qty: event.target.value })}
                              />
                            </TableCell>
                            <TableCell className="min-w-40">
                              <RupiahInput value={line.rate} onChange={(value) => patchEditableLine(line.id, { rate: value })} />
                            </TableCell>
                            <TableCell className="min-w-48">
                              <Select value={line.tax_id || NO_TAX} onValueChange={(next) => patchEditableLine(line.id, { tax_id: next === NO_TAX ? '' : next })}>
                                <SelectTrigger className="w-full">
                                  <SelectValue placeholder={taxesQuery.isLoading ? 'Loading…' : 'No tax'} />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value={NO_TAX}>No tax</SelectItem>
                                  {taxOptions.map((tax) => (
                                    <SelectItem key={tax.id} value={tax.id}>
                                      {tax.name} ({tax.code}){tax.type === 'vat' ? ` — ${tax.rate}%` : ''}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </TableCell>
                            <TableCell className="text-right font-medium">{formatCurrency((Number(line.qty) || 0) * (Number(line.rate) || 0))}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </LineItemTableScroll>
                  <p className="mt-2 text-sm text-muted-foreground">Item, UOM, and the Delivery/Sales Order it came from cannot be changed — only Qty, Rate, and Tax.</p>
                </>
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
