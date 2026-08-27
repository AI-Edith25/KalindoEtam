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
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchItemsLookup, fetchSuppliersLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { fetchGoodsReceipt, createGoodsReceipt, updateGoodsReceipt, submitGoodsReceipt } from '../api/goodsReceiptApi'
import { fetchPurchaseOrder, fetchPurchaseOrders } from '../api/purchaseOrderApi'
import { GoodsReceiptLineItemTable } from '../components/GoodsReceiptLineItemTable'
import { DirectGoodsReceiptLineItemTable } from '../components/DirectGoodsReceiptLineItemTable'
import { computeGrandTotal, computeSubtotal, computeTax } from '@/shared/lib/documentTotals'
import {
  goodsReceiptFormSchema,
  directGoodsReceiptFormSchema,
  type GoodsReceiptEditorValues,
  type DirectGoodsReceiptEditorValues,
} from '../lib/goodsReceiptFormSchema'
import type { GoodsReceiptItem, PurchaseOrderItem } from '../types'

const emptyValues: GoodsReceiptEditorValues = {
  warehouse_id: '',
  receipt_date: '',
  due_date: '',
  remarks: '',
  items: [],
}

const emptyDirectValues: DirectGoodsReceiptEditorValues = {
  supplier_id: '',
  warehouse_id: '',
  receipt_date: '',
  due_date: '',
  remarks: '',
  items: [],
}

type ReceiptMode = 'from_po' | 'direct'

/** Sums actual_weight per weight_unit (never mixed across units) — purely informational, never fed into qty/price totals. */
function weightTotalsLabel(lines: { actual_weight?: string; weight_unit?: string }[]): string {
  const totalsByUnit: Record<string, number> = {}

  for (const line of lines) {
    const weight = Number(line.actual_weight || 0)
    if (weight <= 0) continue
    const unit = line.weight_unit || 'ton'
    totalsByUnit[unit] = (totalsByUnit[unit] ?? 0) + weight
  }

  const parts = Object.entries(totalsByUnit).map(([unit, total]) => `${formatNumber(total)} ${unit}`)
  return parts.length > 0 ? parts.join(', ') : '—'
}

export function GoodsReceiptEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selectedPurchaseOrderId, setSelectedPurchaseOrderId] = useState<string | null>(null)
  const [mode, setMode] = useState<ReceiptMode | null>(null)

  const receiptQuery = useQuery({
    queryKey: ['goods-receipts', id],
    queryFn: () => fetchGoodsReceipt(id!),
    enabled: isEdit,
  })

  // An existing draft's own purchase_order_id decides its mode — a direct receipt can't
  // gain a PO on edit, and vice versa (create() branches once, at creation, only).
  const isDirectMode = isEdit ? receiptQuery.data?.purchase_order_id === null : mode === 'direct'

  const purchaseOrderId = isEdit ? receiptQuery.data?.purchase_order_id : (selectedPurchaseOrderId ?? undefined)

  // Eligible = submitted and not fully received. Fetched only in create mode, before a PO is picked.
  const eligibleOrdersQuery = useQuery({
    queryKey: ['purchase-orders-eligible-for-receipt'],
    queryFn: () => fetchPurchaseOrders({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit && mode === 'from_po',
  })
  const eligibleOrders = (eligibleOrdersQuery.data?.data ?? []).filter((po) => !po.is_fully_received)

  // Always re-fetched fresh (not reused from the eligible-list cache) so outstanding quantities are current at the moment of receiving.
  const purchaseOrderQuery = useQuery({
    queryKey: ['purchase-orders', purchaseOrderId],
    queryFn: () => fetchPurchaseOrder(purchaseOrderId!),
    enabled: !!purchaseOrderId && !isDirectMode,
  })

  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup, enabled: isDirectMode })
  const itemsLookup = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup, enabled: isDirectMode })

  // Both forms always exist (Rules of Hooks) — only the one matching the active mode is rendered/submitted.
  const form = useForm<GoodsReceiptEditorValues>({
    resolver: zodResolver(goodsReceiptFormSchema),
    defaultValues: emptyValues,
  })
  const directForm = useForm<DirectGoodsReceiptEditorValues>({
    resolver: zodResolver(directGoodsReceiptFormSchema),
    defaultValues: emptyDirectValues,
  })

  useEffect(() => {
    const receipt = receiptQuery.data
    if (!receipt) return

    if (receipt.status !== 'draft') {
      toast.error('Only draft goods receipts can be edited.')
      navigate(`/purchase/goods-receipts/${receipt.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [receiptQuery.data])

  useEffect(() => {
    const purchaseOrder = purchaseOrderQuery.data
    if (!purchaseOrder || isDirectMode) return

    const poItemById = new Map(purchaseOrder.items.map((poItem) => [poItem.id, poItem]))

    const buildRow = (poItem: PurchaseOrderItem, existing?: GoodsReceiptItem) => ({
      purchase_order_item_id: poItem.id,
      item_code: poItem.item_code ?? '',
      item_name: poItem.item_name ?? '',
      rate: Number(poItem.rate),
      ordered: Number(poItem.qty),
      alreadyReceived: Number(poItem.received_qty),
      remaining: Number(poItem.outstanding_qty),
      allowOverReceipt: poItem.allow_over_receipt ?? false,
      receiveNow: String(existing?.qty ?? 0),
      actual_weight: existing?.actual_weight != null ? String(existing.actual_weight) : '',
      weight_unit: (existing?.weight_unit as 'kg' | 'ton' | null) ?? 'ton',
      weighbridge_ref: existing?.weighbridge_ref ?? '',
    })

    // Edit mode rebuilds rows from the saved draft's own lines — preserves duplicate
    // purchase_order_item_id rows (multiple truck loads of the same item). Create mode has no
    // draft yet, so it prefills one convenience row per PO item, same as before.
    const items =
      isEdit && receiptQuery.data
        ? receiptQuery.data.items
            .map((line) => {
              const poItem = line.purchase_order_item_id ? poItemById.get(line.purchase_order_item_id) : undefined
              return poItem ? buildRow(poItem, line) : null
            })
            .filter((row): row is NonNullable<typeof row> => row !== null)
        : purchaseOrder.items.map((poItem) => buildRow(poItem))

    form.reset({
      warehouse_id: receiptQuery.data?.warehouse_id ?? '',
      receipt_date: receiptQuery.data?.receipt_date ?? '',
      due_date: receiptQuery.data?.due_date ?? '',
      remarks: receiptQuery.data?.remarks ?? '',
      items,
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [purchaseOrderQuery.data, receiptQuery.data, isDirectMode, isEdit])

  useEffect(() => {
    if (!isDirectMode) return
    const receipt = receiptQuery.data

    // Create mode starts empty (no receipt yet); edit mode pre-fills from the loaded draft.
    if (isEdit && !receipt) return

    directForm.reset({
      supplier_id: receipt?.supplier_id ?? '',
      warehouse_id: receipt?.warehouse_id ?? '',
      receipt_date: receipt?.receipt_date ?? '',
      due_date: receipt?.due_date ?? '',
      remarks: receipt?.remarks ?? '',
      items: (receipt?.items ?? []).map((line) => ({
        item_id: line.item_id,
        item_code: line.item_code,
        item_name: line.item_name,
        qty: String(line.qty),
        rate: String(line.rate),
        actual_weight: line.actual_weight != null ? String(line.actual_weight) : '',
        weight_unit: (line.weight_unit as 'kg' | 'ton' | null) ?? 'ton',
        weighbridge_ref: line.weighbridge_ref ?? '',
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isDirectMode, receiptQuery.data, isEdit])

  const saveMutation = useMutation({
    mutationFn: (values: GoodsReceiptEditorValues) => {
      const items = values.items
        .filter((line) => Number(line.receiveNow) > 0)
        .map((line) => ({
          purchase_order_item_id: line.purchase_order_item_id,
          qty: Number(line.receiveNow),
          actual_weight: line.actual_weight ? Number(line.actual_weight) : null,
          weight_unit: line.weight_unit || null,
          weighbridge_ref: line.weighbridge_ref || null,
        }))

      if (isEdit) {
        return updateGoodsReceipt(id!, {
          warehouse_id: values.warehouse_id,
          receipt_date: values.receipt_date,
          due_date: values.due_date,
          remarks: values.remarks || null,
          items,
        })
      }

      return createGoodsReceipt({
        purchase_order_id: purchaseOrderId!,
        warehouse_id: values.warehouse_id,
        receipt_date: values.receipt_date,
        due_date: values.due_date,
        remarks: values.remarks || null,
        items,
      })
    },
    onSuccess: (receipt) => {
      queryClient.invalidateQueries({ queryKey: ['goods-receipts'] })
      toast.success(isEdit ? 'Receipt details updated.' : 'Goods received. Confirm to update stock and create the payable.')
      if (!isEdit) {
        navigate(`/purchase/goods-receipts/${receipt.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const saveDirectMutation = useMutation({
    mutationFn: (values: DirectGoodsReceiptEditorValues) => {
      const items = values.items.map((line) => ({
        item_id: line.item_id,
        qty: Number(line.qty),
        rate: Number(line.rate),
        actual_weight: line.actual_weight ? Number(line.actual_weight) : null,
        weight_unit: line.weight_unit || null,
        weighbridge_ref: line.weighbridge_ref || null,
      }))

      if (isEdit) {
        return updateGoodsReceipt(id!, {
          warehouse_id: values.warehouse_id,
          receipt_date: values.receipt_date,
          due_date: values.due_date,
          remarks: values.remarks || null,
          items,
        })
      }

      return createGoodsReceipt({
        purchase_order_id: null,
        supplier_id: values.supplier_id,
        warehouse_id: values.warehouse_id,
        receipt_date: values.receipt_date,
        due_date: values.due_date,
        remarks: values.remarks || null,
        items,
      })
    },
    onSuccess: (receipt) => {
      queryClient.invalidateQueries({ queryKey: ['goods-receipts'] })
      toast.success(isEdit ? 'Receipt details updated.' : 'Goods received. Confirm to update stock and create the payable.')
      if (!isEdit) {
        navigate(`/purchase/goods-receipts/${receipt.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitGoodsReceipt(id!),
    onSuccess: (receipt) => {
      queryClient.invalidateQueries({ queryKey: ['goods-receipts'] })
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      toast.success('Receipt confirmed — stock updated.')
      navigate(`/purchase/goods-receipts/${receipt.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const watchedItems = form.watch('items')
  const receivingNowLines = (watchedItems ?? []).map((line) => ({ qty: line.receiveNow, rate: line.rate }))
  const subtotal = computeSubtotal(receivingNowLines)
  const tax = computeTax()
  const grandTotal = computeGrandTotal(receivingNowLines)
  const totalQty = (watchedItems ?? []).reduce((sum, line) => sum + Number(line.receiveNow || 0), 0)
  const totalWeightLabel = weightTotalsLabel(watchedItems ?? [])

  const directWatchedItems = directForm.watch('items')
  const directSubtotal = computeSubtotal(directWatchedItems ?? [])
  const directGrandTotal = computeGrandTotal(directWatchedItems ?? [])
  const directTotalQty = (directWatchedItems ?? []).reduce((sum, line) => sum + Number(line.qty || 0), 0)
  const directTotalWeightLabel = weightTotalsLabel(directWatchedItems ?? [])

  if (isEdit && receiptQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): choose From Purchase Order or Direct/no PO, then (for From PO) pick the order.
  if (!isEdit && (mode === null || (mode === 'from_po' && !selectedPurchaseOrderId))) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Goods Receipt" description="Receive goods against a Purchase Order, or record a direct receipt with no PO." />
        <Card>
          <CardHeader>
            <CardTitle>{mode === 'from_po' ? 'Select Purchase Order' : 'How was this received?'}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {mode === null ? (
              <div className="flex gap-2">
                <Button type="button" onClick={() => setMode('from_po')}>
                  From Purchase Order
                </Button>
                <Button type="button" variant="outline" onClick={() => setMode('direct')}>
                  Direct Receipt (no Purchase Order)
                </Button>
              </div>
            ) : (
              <>
                <Select value="" onValueChange={setSelectedPurchaseOrderId} disabled={eligibleOrdersQuery.isLoading}>
                  <SelectTrigger className="w-full sm:w-96">
                    <SelectValue
                      placeholder={
                        eligibleOrdersQuery.isLoading
                          ? 'Loading…'
                          : eligibleOrders.length === 0
                            ? 'No purchase orders with outstanding items'
                            : 'Select purchase order'
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {eligibleOrders.map((po) => (
                      <SelectItem key={po.id} value={po.id}>
                        {po.document_number} — {po.supplier?.supplier_name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  Only submitted purchase orders with outstanding (not yet fully received) items are shown.
                </p>
                <Button type="button" variant="outline" className="self-start" onClick={() => setMode(null)}>
                  Back
                </Button>
              </>
            )}
            <Button type="button" variant="outline" className="self-start" onClick={() => navigate('/purchase/goods-receipts')}>
              Cancel
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  if (isDirectMode) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title={isEdit ? `Edit ${receiptQuery.data?.document_number ?? 'Goods Receipt'}` : 'New Direct Goods Receipt'}
          description="Standalone receipt — no source Purchase Order."
        />

        <Form {...directForm}>
          <form onSubmit={directForm.handleSubmit((values) => saveDirectMutation.mutate(values))} className="flex flex-col gap-4">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Receipt Details</CardTitle>
                <StatusBadge status={isEdit ? (receiptQuery.data?.status ?? 'draft') : 'draft'} />
              </CardHeader>
              <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField
                  control={directForm.control}
                  name="supplier_id"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Supplier</FormLabel>
                      <Select value={field.value} onValueChange={field.onChange} disabled={isEdit}>
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue placeholder={suppliers.isLoading ? 'Loading…' : 'Select supplier'} />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {suppliers.data?.map((supplier) => (
                            <SelectItem key={supplier.id} value={supplier.id}>
                              {supplier.supplier_name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={directForm.control}
                  name="warehouse_id"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Warehouse</FormLabel>
                      <Select value={field.value} onValueChange={field.onChange}>
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue placeholder={warehouses.isLoading ? 'Loading…' : 'Select warehouse'} />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {warehouses.data?.map((warehouse) => (
                            <SelectItem key={warehouse.id} value={warehouse.id}>
                              {warehouse.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={directForm.control}
                  name="receipt_date"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Receipt Date</FormLabel>
                      <FormControl>
                        <Input type="date" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={directForm.control}
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
                  control={directForm.control}
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
                <DirectGoodsReceiptLineItemTable form={directForm} items={itemsLookup.data ?? []} itemsLoading={itemsLookup.isLoading} />
                {directForm.formState.errors.items?.root && (
                  <p className="mt-2 text-sm text-destructive">{directForm.formState.errors.items.root.message}</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardContent className="flex flex-col items-end gap-1.5 py-4">
                <div className="flex w-full max-w-64 justify-between text-sm">
                  <span className="text-muted-foreground">Total Qty (satuan)</span>
                  <span>{formatNumber(directTotalQty)}</span>
                </div>
                <div className="flex w-full max-w-64 justify-between text-sm">
                  <span className="text-muted-foreground">Total Berat Aktual</span>
                  <span>{directTotalWeightLabel}</span>
                </div>
                <Separator className="w-full max-w-64" />
                <div className="flex w-full max-w-64 justify-between text-base font-semibold">
                  <span>Total</span>
                  <span>{formatCurrency(directGrandTotal || directSubtotal)}</span>
                </div>
                <p className="w-full max-w-64 text-xs text-muted-foreground">
                  Qty = jumlah satuan barang. Berat aktual hanya catatan timbangan, tidak memengaruhi nilai.
                </p>
              </CardContent>
            </Card>

            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => navigate('/purchase/goods-receipts')}>
                Cancel
              </Button>
              <Button type="submit" variant="outline" disabled={saveDirectMutation.isPending}>
                {saveDirectMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                Receive Goods
              </Button>
              {isEdit && receiptQuery.data?.status === 'draft' && (
                <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                  {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                  Confirm Receipt
                </Button>
              )}
            </div>
          </form>
        </Form>
      </div>
    )
  }

  const purchaseOrder = purchaseOrderQuery.data

  if (!purchaseOrder) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${receiptQuery.data?.document_number ?? 'Goods Receipt'}` : 'New Goods Receipt'}
        description={`Receiving against ${purchaseOrder.document_number} — ${purchaseOrder.supplier?.supplier_name ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Receipt Details</CardTitle>
              <StatusBadge status={isEdit ? (receiptQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Purchase Order</span>
                <span className="text-sm font-medium">
                  {purchaseOrder.document_number} — {purchaseOrder.supplier?.supplier_name}
                </span>
              </div>
              <FormField
                control={form.control}
                name="warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Warehouse</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={warehouses.isLoading ? 'Loading…' : 'Select warehouse'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {warehouses.data?.map((warehouse) => (
                          <SelectItem key={warehouse.id} value={warehouse.id}>
                            {warehouse.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div />
              <FormField
                control={form.control}
                name="receipt_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Receipt Date</FormLabel>
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
              <GoodsReceiptLineItemTable form={form} purchaseOrderItems={purchaseOrder.items} />
              {form.formState.errors.items?.root && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.root.message}</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col items-end gap-1.5 py-4">
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">Total Qty (satuan)</span>
                <span>{formatNumber(totalQty)}</span>
              </div>
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">Total Berat Aktual</span>
                <span>{totalWeightLabel}</span>
              </div>
              <Separator className="w-full max-w-64" />
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
              <p className="w-full max-w-64 text-xs text-muted-foreground">
                Qty = jumlah satuan barang. Berat aktual hanya catatan timbangan, tidak memengaruhi nilai.
              </p>
            </CardContent>
          </Card>

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && receiptQuery.data?.status === 'draft'
              ? 'Receiving Goods records what arrived. Confirming updates stock levels and creates the payable to your supplier.'
              : 'Recording quantities here doesn’t move stock yet — you’ll confirm the receipt on the next screen.'}
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/purchase/goods-receipts')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Receive Goods
            </Button>
            {isEdit && receiptQuery.data?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Receipt
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
