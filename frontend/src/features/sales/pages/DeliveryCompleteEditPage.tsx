import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Lock, Save, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { ConfirmationDialog } from '@/components/shared/ConfirmationDialog'
import { DiscountInput } from '@/components/shared/DiscountInput'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { computeSubtotal, lineDiscountAmount, lineNetAmount, lineTaxAmount } from '@/shared/lib/documentTotals'
import { searchCustomersLookup, searchItemsLookup, fetchSalesPersonsLookup, fetchTaxesLookup, fetchTermsOfPaymentLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import type { Customer, Item } from '@/features/master/types'
import { fetchDelivery, updateDelivery } from '../api/deliveryApi'
import { fetchSalesOrder } from '../api/salesOrderApi'
import { allocateSoLineDiscount } from '../lib/deliveryDiscount'
import type { DeliveryItem } from '../types'

interface EditableLine {
  key: string
  id?: string
  // Null for a Direct Delivery line (no source Sales Order) — see DeliveryService::createDirect().
  sales_order_item_id: string | null
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty: string
  rate: string
  // Only meaningful (editable) for a Direct Delivery line — an SO-sourced line's discount is
  // always derived from its Sales Order line instead, see rowDiscount() below.
  discount_type: string
  discount_value: string
  tax_id: string
  is_invoiced: boolean
}

let newLineCounter = 0
const nextLineKey = () => `new-${++newLineCounter}`

function toEditableLine(line: DeliveryItem): EditableLine {
  return {
    key: line.id,
    id: line.id,
    sales_order_item_id: line.sales_order_item_id,
    item_id: line.item_id,
    item_code: line.item_code,
    item_name: line.item_name,
    uom: line.uom,
    qty: String(line.qty),
    rate: String(line.rate),
    discount_type: line.discount_type,
    discount_value: String(line.discount_value ?? 0),
    tax_id: line.tax_id ?? '',
    is_invoiced: line.is_invoiced,
  }
}

/**
 * A Complete Delivery used to be fully locked — this is the edit screen for that relaxation
 * (DeliveryService::updateComplete()). Header fields are always freely editable; a line already
 * referenced by an Invoice keeps its Item/UOM fixed and can't be removed, but Qty/Rate/Tax still
 * apply (the Invoice snapshotted its own copy already and is never touched by this).
 * Deliberately a separate, compact component from DeliveryEditorPage's own Pending-only wizard —
 * that page is built around "how much to deliver against outstanding SO qty", a different shape
 * from "correct what was already recorded".
 */
export function DeliveryCompleteEditPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const deliveryQuery = useQuery({ queryKey: ['deliveries', id], queryFn: () => fetchDelivery(id!) })
  const delivery = deliveryQuery.data

  // A Direct Delivery (no source Sales Order) has no Sales Order to fetch — see
  // DeliveryService::createDirect(). Its "Add item" control picks an Item directly instead.
  const salesOrderQuery = useQuery({
    queryKey: ['sales-orders', delivery?.sales_order_id],
    queryFn: () => fetchSalesOrder(delivery!.sales_order_id!),
    enabled: !!delivery && delivery.sales_order_id !== null,
  })

  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const termsOfPaymentQuery = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })

  const [customerId, setCustomerId] = useState('')
  const [customerOption, setCustomerOption] = useState<SearchableSelectOption<Customer> | undefined>(undefined)
  const [warehouseId, setWarehouseId] = useState('')
  const [salesPersonId, setSalesPersonId] = useState('')
  const [deliveryDate, setDeliveryDate] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [termsOfPaymentId, setTermsOfPaymentId] = useState('')
  const [attention, setAttention] = useState('')
  const [tel, setTel] = useState('')
  const [fax, setFax] = useState('')
  const [fleet, setFleet] = useState('')
  const [driver, setDriver] = useState('')
  const [remarks, setRemarks] = useState('')
  const [lines, setLines] = useState<EditableLine[] | null>(null)
  const [confirmingInvoiced, setConfirmingInvoiced] = useState(false)

  // Mirrors DeliveryEditorPage's own "mount fresh with the right defaults" convention — a plain
  // one-time sync from server data (no form library needed for a page this size), fires once.
  if (delivery && lines === null) {
    setCustomerId(delivery.customer_id)
    setCustomerOption({ value: delivery.customer_id, label: `${delivery.customer?.customer_code ?? ''} — ${delivery.customer?.customer_name ?? ''}` })
    setWarehouseId(delivery.warehouse_id)
    setSalesPersonId(delivery.sales_person_id ?? '')
    setDeliveryDate(delivery.delivery_date)
    setDueDate(delivery.due_date)
    setTermsOfPaymentId(delivery.terms_of_payment_id ?? '')
    setAttention(delivery.attention ?? '')
    setTel(delivery.tel ?? '')
    setFax(delivery.fax ?? '')
    setFleet(delivery.fleet ?? '')
    setDriver(delivery.driver ?? '')
    setRemarks(delivery.remarks ?? '')
    setLines(delivery.items.map(toEditableLine))
  }

  const loadCustomerOptions = async (query: string) => {
    const customers = await searchCustomersLookup(query)
    return customers.map((customer) => ({ value: customer.id, label: `${customer.customer_code} — ${customer.customer_name}`, data: customer }))
  }

  const usedSoItemIds = new Set((lines ?? []).map((line) => line.sales_order_item_id))
  const addableSoItems = (salesOrderQuery.data?.items ?? []).filter((soItem) => !usedSoItemIds.has(soItem.id))

  const addLine = (soItemId: string) => {
    const soItem = salesOrderQuery.data?.items.find((i) => i.id === soItemId)
    if (!soItem) return

    setLines((prev) => [
      ...(prev ?? []),
      {
        key: nextLineKey(),
        sales_order_item_id: soItem.id,
        item_id: soItem.item_id,
        item_code: soItem.item_code ?? '',
        item_name: soItem.item_name ?? '',
        uom: soItem.uom ?? '',
        qty: '1',
        rate: String(soItem.rate),
        discount_type: soItem.discount_type,
        discount_value: String(soItem.discount_value ?? 0),
        tax_id: soItem.tax_id ?? '',
        is_invoiced: false,
      },
    ])
  }

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: `${item.item_code} — ${item.item_name}`, data: item }))
  }

  // Direct Delivery's own "Add item" — no Sales Order line to snapshot, so Rate/Tax start from
  // the Item's own standard_rate/unset, same as DirectDeliveryLineItemTable's handleItemChange.
  const addDirectLine = (item: Item) => {
    setLines((prev) => [
      ...(prev ?? []),
      {
        key: nextLineKey(),
        sales_order_item_id: null,
        item_id: item.id,
        item_code: item.item_code,
        item_name: item.item_name,
        uom: item.uom ? `${item.uom.name}${item.uom.symbol ? ` (${item.uom.symbol})` : ''}` : '',
        qty: '1',
        rate: String(item.standard_rate),
        discount_type: 'amount',
        discount_value: '0',
        tax_id: '',
        is_invoiced: false,
      },
    ])
  }

  const removeLine = (key: string) => setLines((prev) => (prev ?? []).filter((line) => line.key !== key))
  const patchLine = (key: string, patch: Partial<EditableLine>) =>
    setLines((prev) => (prev ?? []).map((line) => (line.key === key ? { ...line, ...patch } : line)))

  const buildPayload = () => ({
    customer_id: customerId,
    warehouse_id: warehouseId,
    sales_person_id: salesPersonId || null,
    delivery_date: deliveryDate,
    due_date: dueDate,
    terms_of_payment_id: termsOfPaymentId || null,
    attention: attention || null,
    tel: tel || null,
    fax: fax || null,
    fleet: fleet || null,
    driver: driver || null,
    remarks: remarks || null,
    lock_version: delivery!.lock_version,
    items: (lines ?? []).map((line) => ({
      id: line.id,
      sales_order_item_id: line.sales_order_item_id,
      item_id: line.item_id,
      qty: Number(line.qty) || 0,
      rate: Number(line.rate) || 0,
      // Only meaningful for a Direct Delivery line — an SO-sourced line's discount is always
      // re-derived server-side from its Sales Order line, same as creation.
      discount_type: line.discount_type as 'amount' | 'percentage',
      discount_value: Number(line.discount_value) || 0,
      tax_id: line.tax_id || null,
    })),
  })

  const saveMutation = useMutation({
    mutationFn: () => updateDelivery(id!, buildPayload()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['deliveries'] })
      toast.success('Delivery updated.')
      navigate(`/sales/deliveries/${id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const handleSaveClick = () => {
    if (delivery?.is_invoiced) {
      setConfirmingInvoiced(true)
      return
    }
    saveMutation.mutate()
  }

  const isDirect = delivery?.sales_order_id === null

  if (deliveryQuery.isLoading || !delivery || (!isDirect && !salesOrderQuery.data) || lines === null) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // An SO-sourced line's discount is always derived from its Sales Order line (never entered
  // here, see allocateSoLineDiscount()'s own docblock); a Direct line's own discount_type/
  // discount_value (editable) drives it directly instead.
  const rowDiscount = (line: EditableLine): { discount_amount: number; net_amount: number } => {
    if (line.sales_order_item_id) {
      const soItem = salesOrderQuery.data?.items.find((item) => item.id === line.sales_order_item_id)
      if (soItem) return allocateSoLineDiscount(soItem, Number(line.qty) || 0, line.rate)
    }

    const grossAmount = (Number(line.qty) || 0) * (Number(line.rate) || 0)

    return { discount_amount: lineDiscountAmount(grossAmount, line), net_amount: lineNetAmount(line) }
  }

  const subtotal = computeSubtotal(lines)
  const discount = lines.reduce((sum, line) => sum + rowDiscount(line).discount_amount, 0)
  const tax = lines.reduce((sum, line) => sum + lineTaxAmount(rowDiscount(line).net_amount, taxesQuery.data?.find((tx) => tx.id === line.tax_id)), 0)
  const grandTotal = subtotal - discount + tax

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={`Edit ${delivery.document_number ?? 'Delivery'}`}
        description={delivery.sales_order ? `Delivering against ${delivery.sales_order.document_number}.` : 'Direct delivery (no Sales Order).'}
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Delivery Details</CardTitle>
          <StatusBadge status={delivery.status} />
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">Document Number</span>
            <span className="text-sm font-medium">{delivery.document_number ?? '—'}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">Sales Order</span>
            <span className="text-sm font-medium">{delivery.sales_order?.document_number ?? '—'}</span>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Customer</label>
            <SearchableSelect
              loadOptions={loadCustomerOptions}
              selectedOption={customerOption}
              value={customerId}
              onChange={(value, option) => {
                setCustomerId(value ?? '')
                setCustomerOption(option)
              }}
              placeholder="Select customer"
              aria-label="Customer"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Location</label>
            <SearchableSelect
              options={(warehousesQuery.data ?? []).map((w) => ({ value: w.id, label: w.name }))}
              value={warehouseId}
              onChange={(value) => setWarehouseId(value ?? '')}
              loading={warehousesQuery.isLoading}
              clearable={false}
              placeholder="Select location"
              aria-label="Location"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Sales Person</label>
            <SearchableSelect
              options={(salesPersonsQuery.data ?? []).map((sp) => ({ value: sp.id, label: sp.name }))}
              value={salesPersonId}
              onChange={(value) => setSalesPersonId(value ?? '')}
              loading={salesPersonsQuery.isLoading}
              placeholder={delivery.sales_order?.sales_person?.name || 'None'}
              aria-label="Sales Person"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Delivery Date</label>
            <Input type="date" value={deliveryDate} onChange={(e) => setDeliveryDate(e.target.value)} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Due Date</label>
            <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Terms of Payment</label>
            <SearchableSelect
              options={(termsOfPaymentQuery.data ?? []).map((top) => ({ value: top.id, label: `${top.name} (${top.code})` }))}
              value={termsOfPaymentId}
              onChange={(value) => setTermsOfPaymentId(value ?? '')}
              loading={termsOfPaymentQuery.isLoading}
              placeholder="None"
              aria-label="Terms of Payment"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Attn</label>
            <Input value={attention} onChange={(e) => setAttention(e.target.value)} placeholder={delivery.sales_order?.attention || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Tel</label>
            <Input value={tel} onChange={(e) => setTel(e.target.value)} placeholder={delivery.sales_order?.tel || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Fax</label>
            <Input value={fax} onChange={(e) => setFax(e.target.value)} placeholder={delivery.sales_order?.fax || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Fleet</label>
            <Input value={fleet} onChange={(e) => setFleet(e.target.value)} placeholder="Optional" />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Driver</label>
            <Input value={driver} onChange={(e) => setDriver(e.target.value)} placeholder="Optional" />
          </div>
          <div className="flex flex-col gap-1.5 sm:col-span-2">
            <label className="text-sm font-medium">Notes</label>
            <Textarea value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Optional" />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Delivered Items</CardTitle>
          {isDirect ? (
            <SearchableSelect
              className="w-64"
              loadOptions={loadItemOptions}
              value=""
              onChange={(_value, option) => option?.data && addDirectLine(option.data)}
              placeholder="Add item…"
              aria-label="Add item"
            />
          ) : (
            addableSoItems.length > 0 && (
              <SearchableSelect
                className="w-64"
                options={addableSoItems.map((i) => ({ value: i.id, label: `${i.item_code} — ${i.item_name}` }))}
                value=""
                onChange={(value) => value && addLine(value)}
                placeholder="Add item from Sales Order…"
                aria-label="Add item"
              />
            )
          )}
        </CardHeader>
        <CardContent>
          <LineItemTableScroll>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className={STICKY_FIRST_COL}>Item</TableHead>
                  <TableHead className="w-28 text-right">Qty</TableHead>
                  <TableHead className="w-40 text-right">Rate</TableHead>
                  <TableHead className="w-40">Discount</TableHead>
                  <TableHead className="w-48">Tax</TableHead>
                  <TableHead className="w-36 text-right">Amount</TableHead>
                  <TableHead className="w-32 text-right">Tax Amount</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((line) => {
                  const { discount_amount: lineDiscount, net_amount: lineNet } = rowDiscount(line)

                  return (
                  <TableRow key={line.key}>
                    <TableCell className={STICKY_FIRST_COL}>
                      <div className="flex items-center gap-1.5">
                        <div>
                          <div className="truncate font-medium">{line.item_code}</div>
                          <div className="truncate text-xs text-muted-foreground">
                            {line.item_name}
                            {line.uom ? ` · ${line.uom}` : ''}
                          </div>
                        </div>
                        {line.is_invoiced && (
                          <TooltipProvider>
                            <Tooltip>
                              <TooltipTrigger>
                                <Lock className="size-3.5 shrink-0 text-muted-foreground" />
                              </TooltipTrigger>
                              <TooltipContent>Sudah di-invoice — item tidak bisa diganti/dihapus, tapi Qty/Rate/Tax masih bisa diubah</TooltipContent>
                            </Tooltip>
                          </TooltipProvider>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="min-w-28">
                      <Input type="number" min={0.01} step="0.01" className="text-right" value={line.qty} onChange={(e) => patchLine(line.key, { qty: e.target.value })} />
                    </TableCell>
                    <TableCell className="min-w-40">
                      <RupiahInput value={line.rate} onChange={(value) => patchLine(line.key, { rate: value })} />
                    </TableCell>
                    <TableCell className="min-w-40">
                      {line.sales_order_item_id ? (
                        <span className="text-sm text-muted-foreground">{lineDiscount > 0 ? `-${formatCurrency(lineDiscount)}` : '—'}</span>
                      ) : (
                        <DiscountInput
                          type={line.discount_type}
                          value={line.discount_value}
                          onTypeChange={(value) => patchLine(line.key, { discount_type: value })}
                          onValueChange={(value) => patchLine(line.key, { discount_value: value })}
                        />
                      )}
                    </TableCell>
                    <TableCell className="min-w-48">
                      <SearchableSelect
                        options={[{ value: '', label: 'No tax' }, ...(taxesQuery.data ?? []).map((t) => ({ value: t.id, label: `${t.name} (${t.code})` }))]}
                        value={line.tax_id}
                        onChange={(value) => patchLine(line.key, { tax_id: value ?? '' })}
                        clearable={false}
                        placeholder="No tax"
                        aria-label="Tax"
                      />
                    </TableCell>
                    <TableCell className="text-right font-medium">{formatCurrency(lineNet)}</TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {formatCurrency(lineTaxAmount(lineNet, taxesQuery.data?.find((tx) => tx.id === line.tax_id)))}
                    </TableCell>
                    <TableCell>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => removeLine(line.key)}
                        disabled={line.is_invoiced}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </TableCell>
                  </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </LineItemTableScroll>
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
            <span>{formatCurrency(grandTotal)}</span>
          </div>
        </CardContent>
      </Card>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => navigate(`/sales/deliveries/${id}`)}>
          Cancel
        </Button>
        <Button type="button" onClick={handleSaveClick} disabled={saveMutation.isPending}>
          {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
          Save
        </Button>
      </div>

      <ConfirmationDialog
        open={confirmingInvoiced}
        onOpenChange={setConfirmingInvoiced}
        title="Delivery ini sudah di-invoice"
        description="Perubahan tidak otomatis mengubah invoice yang sudah dibuat dari Delivery ini. Lanjutkan menyimpan perubahan?"
        confirmLabel="Simpan Perubahan"
        onConfirm={() => saveMutation.mutate()}
      />
    </div>
  )
}
