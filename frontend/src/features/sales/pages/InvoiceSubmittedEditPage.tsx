import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Separator } from '@/components/ui/separator'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { ConfirmationDialog } from '@/components/shared/ConfirmationDialog'
import { DiscountInput } from '@/components/shared/DiscountInput'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { computeLineTaxTotal, computeSubtotal, computeTotalDiscount, lineNetAmount } from '@/shared/lib/documentTotals'
import { qtyDecimalPlaces, type QtyCategory } from '@/shared/lib/qty'
import { fetchBranches, fetchSalesPersonsLookup, fetchTaxesLookup, fetchTermsOfPaymentLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { fetchInvoice, updateInvoice } from '../api/invoiceApi'
import type { InvoiceItem } from '../types'

interface EditableLine {
  id: string
  item_code: string | null
  item_name: string
  uom: string | null
  qty: string
  // Snapshotted at creation — drives whole-vs-decimal qty input, same as InvoiceEditorPage's own field.
  qty_category: QtyCategory
  rate: string
  discount_type: string
  discount_value: string
  tax_id: string
}

function toEditableLine(line: InvoiceItem): EditableLine {
  return {
    id: line.id,
    item_code: line.item_code,
    item_name: line.item_name,
    uom: line.uom,
    qty: String(line.qty),
    qty_category: line.qty_category ?? 'unit',
    rate: String(line.rate),
    discount_type: line.discount_type,
    discount_value: String(line.discount_value ?? 0),
    tax_id: line.tax_id ?? '',
  }
}

/**
 * A Submitted Invoice used to be fully locked — this is the edit screen for that relaxation
 * (InvoiceService::updateSubmitted()). Item identity (Item Code/Name/UOM) and Delivery/Sales
 * Order linkage stay fixed — no add/remove — but Qty/Rate/Tax per line, and a set of header
 * fields, are editable, even once the invoice has payments/Credit/Debit Notes applied. Changing
 * Grand Total reverses the invoice's posted Journal Entry and posts a fresh one, and resizes
 * Accounts Receivable by the delta. Deliberately a separate, compact component from
 * InvoiceEditorPage's own Draft-only, multi-mode (Goods/Direct/Transportation) wizard.
 */
export function InvoiceSubmittedEditPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const invoiceQuery = useQuery({ queryKey: ['invoices', id], queryFn: () => fetchInvoice(id!) })
  const invoice = invoiceQuery.data

  const branchesQuery = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })
  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const termsOfPaymentQuery = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })

  const [invoiceDate, setInvoiceDate] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [termsOfPaymentId, setTermsOfPaymentId] = useState('')
  const [salesPersonId, setSalesPersonId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [locationWarehouseId, setLocationWarehouseId] = useState('')
  const [attention, setAttention] = useState('')
  const [tel, setTel] = useState('')
  const [fax, setFax] = useState('')
  const [reference1, setReference1] = useState('')
  const [reference2, setReference2] = useState('')
  const [customerAddress, setCustomerAddress] = useState('')
  const [customerPhone, setCustomerPhone] = useState('')
  const [remarks, setRemarks] = useState('')
  const [affectsStock, setAffectsStock] = useState(false)
  const [lines, setLines] = useState<EditableLine[] | null>(null)
  const [confirmingPaid, setConfirmingPaid] = useState(false)

  if (invoice && lines === null) {
    setInvoiceDate(invoice.invoice_date)
    setDueDate(invoice.due_date)
    setTermsOfPaymentId(invoice.terms_of_payment_id ?? '')
    setSalesPersonId(invoice.sales_person_id ?? '')
    setBranchId(invoice.branch_id ?? '')
    setLocationWarehouseId(invoice.location_warehouse_id ?? '')
    setAttention(invoice.attention ?? '')
    setTel(invoice.tel ?? '')
    setFax(invoice.fax ?? '')
    setReference1(invoice.reference_1 ?? '')
    setReference2(invoice.reference_2 ?? '')
    setCustomerAddress(invoice.customer_address ?? '')
    setCustomerPhone(invoice.customer_phone ?? '')
    setRemarks(invoice.remarks ?? '')
    setAffectsStock(invoice.affects_stock)
    setLines(invoice.items.map(toEditableLine))
  }

  const patchLine = (id: string, patch: Partial<EditableLine>) =>
    setLines((prev) => (prev ?? []).map((line) => (line.id === id ? { ...line, ...patch } : line)))

  const isTransportation = invoice?.invoice_type === 'transportation'

  const buildPayload = () => ({
    invoice_date: invoiceDate,
    due_date: dueDate,
    terms_of_payment_id: termsOfPaymentId || null,
    sales_person_id: salesPersonId || null,
    branch_id: branchId || null,
    location_warehouse_id: locationWarehouseId || null,
    attention: attention || null,
    tel: tel || null,
    fax: fax || null,
    reference_1: reference1 || null,
    reference_2: reference2 || null,
    customer_address: customerAddress || null,
    customer_phone: customerPhone || null,
    remarks: remarks || null,
    affects_stock: affectsStock,
    lock_version: invoice!.lock_version,
    // Transportation rejects any `items` key here — see InvoiceService::updateSubmitted(), which
    // sends Transportation rate changes through the dedicated "Ubah Nominal" flow instead. Sending
    // it unconditionally made every save (even a header-only edit like Reference) 422 for them.
    ...(isTransportation
      ? {}
      : {
          items: (lines ?? []).map((line) => ({
            id: line.id,
            qty: Number(line.qty) || 0,
            rate: Number(line.rate) || 0,
            discount_type: line.discount_type as 'amount' | 'percentage',
            discount_value: Number(line.discount_value) || 0,
            tax_id: line.tax_id || null,
          })),
        }),
  })

  const saveMutation = useMutation({
    mutationFn: () => updateInvoice(id!, buildPayload()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Invoice updated.')
      navigate(`/sales/invoices/${id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const handleSaveClick = () => {
    if (Number(invoice?.paid_amount) > 0) {
      setConfirmingPaid(true)
      return
    }
    saveMutation.mutate()
  }

  if (invoiceQuery.isLoading || !invoice || lines === null) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const subtotal = computeSubtotal(lines)
  const discount = computeTotalDiscount(lines)
  const tax = computeLineTaxTotal(lines, (line) => taxesQuery.data?.find((tx) => tx.id === line.tax_id))
  const grandTotal = subtotal - discount + tax
  const oldGrandTotal = Number(invoice.grand_total)
  const newOutstanding = grandTotal - Number(invoice.paid_amount)

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title={`Edit ${invoice.document_number ?? 'Invoice'}`} description={invoice.deliveries?.length ? `Invoicing ${invoice.deliveries.map((d) => d.document_number).join(', ')}.` : undefined} />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Invoice Details</CardTitle>
          <StatusBadge status={invoice.display_status} />
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">Invoice Number</span>
            <span className="text-sm font-medium">{invoice.document_number ?? '—'}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">Customer</span>
            <span className="text-sm font-medium">{invoice.customer?.customer_name ?? '—'}</span>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Invoice Date</label>
            <Input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} />
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
            <label className="text-sm font-medium">Sales Person</label>
            <SearchableSelect
              options={(salesPersonsQuery.data ?? []).map((sp) => ({ value: sp.id, label: sp.name }))}
              value={salesPersonId}
              onChange={(value) => setSalesPersonId(value ?? '')}
              loading={salesPersonsQuery.isLoading}
              placeholder="None"
              aria-label="Sales Person"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Location (Branch)</label>
            <SearchableSelect
              options={(branchesQuery.data ?? []).map((b) => ({ value: b.id, label: b.name }))}
              value={branchId}
              onChange={(value) => setBranchId(value ?? '')}
              loading={branchesQuery.isLoading}
              placeholder={invoice.sales_order?.branch?.name || 'None'}
              aria-label="Location"
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Location (Warehouse)</label>
            <SearchableSelect
              options={(warehousesQuery.data ?? []).map((w) => ({ value: w.id, label: w.name }))}
              value={locationWarehouseId}
              onChange={(value) => setLocationWarehouseId(value ?? '')}
              loading={warehousesQuery.isLoading}
              placeholder={invoice.location_warehouse?.name || invoice.delivery?.warehouse?.name || 'None'}
              aria-label="Location (Warehouse)"
            />
          </div>
          {invoice.import_source_type !== null && invoice.invoice_type === 'goods' && (
            <div className="flex flex-col gap-1.5 sm:col-span-2">
              <div className="flex items-center gap-2">
                <Switch checked={affectsStock} onCheckedChange={setAffectsStock} />
                <label className="text-sm font-medium">Affects Stock</label>
              </div>
              <p className="text-xs text-muted-foreground">
                This is an imported invoice — stock was never deducted for it. Turning this on deducts FIFO stock from the Location (Warehouse) above when you save.
              </p>
            </div>
          )}
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Attention</label>
            <Input value={attention} onChange={(e) => setAttention(e.target.value)} placeholder={invoice.sales_order?.attention || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Tel</label>
            <Input value={tel} onChange={(e) => setTel(e.target.value)} placeholder={invoice.sales_order?.tel || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Fax</label>
            <Input value={fax} onChange={(e) => setFax(e.target.value)} placeholder={invoice.sales_order?.fax || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Reference 1</label>
            <Input value={reference1} onChange={(e) => setReference1(e.target.value)} placeholder="Optional" />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Reference 2</label>
            <Input value={reference2} onChange={(e) => setReference2(e.target.value)} placeholder="Optional" />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Customer Address</label>
            <Input value={customerAddress} onChange={(e) => setCustomerAddress(e.target.value)} placeholder={invoice.customer?.address || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Customer Phone</label>
            <Input value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} placeholder={invoice.customer?.phone || 'Optional'} />
          </div>
          <div className="flex flex-col gap-1.5 sm:col-span-2">
            <label className="text-sm font-medium">Notes</label>
            <Textarea value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Optional" />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Item List</CardTitle>
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
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((line) => (
                  <TableRow key={line.id}>
                    <TableCell className={STICKY_FIRST_COL}>
                      <div className="truncate font-medium">{line.item_code ?? '—'}</div>
                      <div className="truncate text-xs text-muted-foreground">
                        {line.item_name}
                        {line.uom ? ` · ${line.uom}` : ''}
                      </div>
                    </TableCell>
                    <TableCell className="min-w-28">
                      {(() => {
                        const decimalPlaces = qtyDecimalPlaces(line.qty_category)
                        return (
                          <Input
                            type="number"
                            min={decimalPlaces > 0 ? 0.01 : 1}
                            step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                            className="text-right"
                            value={line.qty}
                            onChange={(e) => patchLine(line.id, { qty: e.target.value })}
                            disabled={isTransportation}
                          />
                        )
                      })()}
                    </TableCell>
                    <TableCell className="min-w-40">
                      <RupiahInput value={line.rate} onChange={(value) => patchLine(line.id, { rate: value })} disabled={isTransportation} />
                    </TableCell>
                    <TableCell className="min-w-40">
                      <DiscountInput
                        type={line.discount_type}
                        value={line.discount_value}
                        onTypeChange={(value) => patchLine(line.id, { discount_type: value })}
                        onValueChange={(value) => patchLine(line.id, { discount_value: value })}
                        disabled={isTransportation}
                      />
                    </TableCell>
                    <TableCell className="min-w-48">
                      <SearchableSelect
                        options={[{ value: '', label: 'No tax' }, ...(taxesQuery.data ?? []).map((t) => ({ value: t.id, label: `${t.name} (${t.code})` }))]}
                        value={line.tax_id}
                        onChange={(value) => patchLine(line.id, { tax_id: value ?? '' })}
                        clearable={false}
                        placeholder="No tax"
                        aria-label="Tax"
                        disabled={isTransportation}
                      />
                    </TableCell>
                    <TableCell className="text-right font-medium">{formatCurrency(lineNetAmount(line))}</TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {formatCurrency(computeLineTaxTotal([line], (l) => taxesQuery.data?.find((tx) => tx.id === l.tax_id)))}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </LineItemTableScroll>
          <p className="mt-2 text-sm text-muted-foreground">
            {isTransportation
              ? 'Transportation invoice — use the "Ubah Nominal" menu to change Rate. Item, UOM, Qty, Rate, Discount, and Tax cannot be changed here.'
              : 'Item, UOM, and the Delivery/Sales Order it came from cannot be changed — only Qty, Rate, and Tax.'}
          </p>
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
        <Button type="button" variant="outline" onClick={() => navigate(`/sales/invoices/${id}`)}>
          Cancel
        </Button>
        <Button type="button" onClick={handleSaveClick} disabled={saveMutation.isPending}>
          {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
          Save
        </Button>
      </div>

      <ConfirmationDialog
        open={confirmingPaid}
        onOpenChange={setConfirmingPaid}
        title="Invoice ini sudah ada pembayaran"
        description={`Invoice ini sudah dibayar ${formatCurrency(invoice.paid_amount)}. Grand Total akan berubah dari ${formatCurrency(oldGrandTotal)} menjadi ${formatCurrency(grandTotal)}, dan Outstanding akan menjadi ${formatCurrency(newOutstanding)}. Lanjutkan menyimpan perubahan?`}
        confirmLabel="Simpan Perubahan"
        onConfirm={() => saveMutation.mutate()}
      />
    </div>
  )
}
