import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueries, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save, Send } from 'lucide-react'
import type { NavigateFunction } from 'react-router-dom'
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
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { SearchBox } from '@/components/shared/SearchBox'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { computeSubtotal, computeTotalDiscount, lineDiscountAmount, lineTaxAmount } from '@/shared/lib/documentTotals'
import { allocateSoLineDiscount } from '../lib/deliveryDiscount'
import { fetchWarehousesLookup, fetchTermsOfPaymentLookup, fetchTaxesLookup, searchCustomersLookup } from '@/features/master/api/lookupsApi'
import { fetchStockBalances } from '@/features/inventory/api/stockApi'
import { addDays } from '@/shared/lib/dateMath'
import { fetchDelivery, createDelivery, updateDelivery, completeDelivery } from '../api/deliveryApi'
import { fetchSalesOrder, fetchSalesOrders } from '../api/salesOrderApi'
import type { Customer } from '@/features/master/types'
import type { Delivery, SalesOrder } from '../types'
import { DeliveryLineItemTable } from '../components/DeliveryLineItemTable'
import { DirectDeliveryLineItemTable } from '../components/DirectDeliveryLineItemTable'
import { deliveryFormSchema, directDeliveryFormSchema, type DeliveryEditorValues, type DirectDeliveryEditorValues } from '../lib/deliveryFormSchema'
import { DeliveryCompleteEditPage } from './DeliveryCompleteEditPage'
import type { SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { parseLocaleQty } from '@/shared/lib/qty'

export function DeliveryEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selectedSalesOrderIds, setSelectedSalesOrderIds] = useState<Set<string>>(new Set())
  const [soSearch, setSoSearch] = useState('')
  // Checking boxes must not auto-advance past the selection screen — with multi-select, the
  // user needs to be able to tick a second/third Sales Order before moving on. An explicit
  // Continue click is what commits the selection and mounts DeliveryForm. Mirrors InvoiceEditorPage.
  const [selectionConfirmed, setSelectionConfirmed] = useState(false)

  const toggleSalesOrder = (salesOrderId: string, checked: boolean) => {
    setSelectedSalesOrderIds((prev) => {
      const next = new Set(prev)
      if (checked) next.add(salesOrderId)
      else next.delete(salesOrderId)
      return next
    })
  }

  const deliveryQuery = useQuery({
    queryKey: ['deliveries', id],
    queryFn: () => fetchDelivery(id!),
    enabled: isEdit,
  })

  // Direct (no Sales Order) creation is removed — only an existing Delivery created that way
  // before this change can still be in direct mode, decided by its own sales_order_id (a direct
  // delivery can't retroactively gain a Sales Order, same mechanism as GoodsReceiptEditorPage's
  // isDirectMode). A brand-new Delivery is always from one or more Sales Orders.
  const isDirectMode = isEdit ? deliveryQuery.data?.sales_order_id === null : false

  // One or more Sales Orders this Delivery is (or will be) built from. In edit mode, every
  // Sales Order already linked (sales_orders pivot — falling back to the anchor sales_order_id
  // for a Delivery created before this pivot existed); in create mode, whatever the user has
  // checked so far.
  const salesOrderIds = isEdit
    ? (deliveryQuery.data?.sales_orders?.map((so) => so.id) ?? (deliveryQuery.data?.sales_order_id ? [deliveryQuery.data.sales_order_id] : []))
    : Array.from(selectedSalesOrderIds)

  // Eligible = approved and not fully delivered. Fetched only in create mode, before any Sales Order is picked.
  const eligibleOrdersQuery = useQuery({
    queryKey: ['sales-orders-eligible-for-delivery', soSearch],
    queryFn: () => fetchSalesOrders({ page: 1, per_page: 100, status: 'approved', ...(soSearch ? { search: soSearch } : {}) }),
    enabled: !isEdit,
  })
  const eligibleOrders = (eligibleOrdersQuery.data?.data ?? []).filter((so) => !so.is_fully_delivered)
  const selectedOrders = eligibleOrders.filter((so) => selectedSalesOrderIds.has(so.id))
  // Once ≥1 Sales Order is checked, only orders from the same Customer *and* Warehouse remain
  // selectable — mirrors InvoiceEditorPage's same-Customer narrowing, extended with the Warehouse
  // constraint DeliveryService::create() also enforces server-side.
  const selectedCustomerId = selectedOrders[0]?.customer_id ?? null
  const selectedWarehouseId = selectedOrders[0]?.warehouse_id ?? null
  const selectableOrders = eligibleOrders.filter(
    (so) => (!selectedCustomerId || so.customer_id === selectedCustomerId) && (!selectedWarehouseId || so.warehouse_id === selectedWarehouseId),
  )

  // Always re-fetched fresh (not reused from the eligible-list cache) so outstanding quantities
  // are current at the moment of delivering — one query per selected/linked Sales Order.
  const salesOrderQueries = useQueries({
    queries: salesOrderIds.map((soId) => ({
      queryKey: ['sales-orders', soId],
      queryFn: () => fetchSalesOrder(soId),
    })),
  })
  const salesOrders = salesOrderQueries.map((query) => query.data).filter((so): so is SalesOrder => !!so)

  useEffect(() => {
    const delivery = deliveryQuery.data
    if (!delivery) return

    if (delivery.status !== 'pending' && delivery.status !== 'complete') {
      toast.error('Cancelled deliveries cannot be edited.')
      navigate(`/sales/deliveries/${delivery.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deliveryQuery.data])

  if (isEdit && deliveryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Complete routes to a separate, purpose-built editor (DeliveryCompleteEditPage) — this
  // page's own form below is built around "how much to deliver against outstanding SO qty", a
  // different shape from "correct what was already recorded". See DeliveryService::updateComplete().
  if (isEdit && deliveryQuery.data?.status === 'complete') {
    return <DeliveryCompleteEditPage />
  }

  // Step 1 (create mode only): pick one or more Sales Orders this Delivery is built from. An
  // explicit Continue commits the selection, same as InvoiceEditorPage's own Delivery picker one
  // level up the chain. Direct/no-Sales-Order creation was removed — every new Delivery now
  // always comes from a Sales Order; an existing direct Delivery is still editable (isDirectMode
  // above), just not creatable anew.
  if (!isEdit && !selectionConfirmed) {
    const allSelectableChecked = selectableOrders.length > 0 && selectableOrders.every((so) => selectedSalesOrderIds.has(so.id))

    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Delivery" description="Deliver against one or more existing Sales Orders from the same Customer and Warehouse." />
        <Card>
          <CardHeader>
            <CardTitle>Select Sales Order(s)</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <SearchBox value={soSearch} onChange={setSoSearch} placeholder="Search document number or customer…" />
            {eligibleOrdersQuery.isLoading ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="size-6 animate-spin text-muted-foreground" />
              </div>
            ) : selectableOrders.length === 0 ? (
              <p className="text-sm text-muted-foreground">No sales orders with outstanding items.</p>
            ) : (
              <div className="overflow-x-auto rounded-md border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-10">
                        <Checkbox
                          checked={allSelectableChecked}
                          onCheckedChange={(checked) => selectableOrders.forEach((so) => toggleSalesOrder(so.id, checked === true))}
                          aria-label="Select all eligible sales orders"
                        />
                      </TableHead>
                      <TableHead>Document Number</TableHead>
                      <TableHead>Customer</TableHead>
                      <TableHead>Order Date</TableHead>
                      <TableHead className="text-right">Items</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {selectableOrders.map((so) => {
                      const checked = selectedSalesOrderIds.has(so.id)
                      return (
                        <TableRow key={so.id} data-state={checked ? 'selected' : undefined}>
                          <TableCell>
                            <Checkbox checked={checked} onCheckedChange={(value) => toggleSalesOrder(so.id, value === true)} aria-label={`Select ${so.document_number}`} />
                          </TableCell>
                          <TableCell className="font-medium">{so.document_number}</TableCell>
                          <TableCell>{so.customer?.customer_name}</TableCell>
                          <TableCell>{so.order_date}</TableCell>
                          <TableCell className="text-right">{so.items.length}</TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
            )}
            <p className="text-sm text-muted-foreground">
              Only approved sales orders with outstanding (not yet fully delivered) items are shown — once you select one, only orders from the same
              Customer and Warehouse remain selectable.
            </p>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => navigate('/sales/deliveries')}>
                Cancel
              </Button>
              <Button type="button" disabled={selectedSalesOrderIds.size === 0} onClick={() => setSelectionConfirmed(true)}>
                Continue
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  if (isDirectMode) {
    return (
      <DirectDeliveryForm
        isEdit={isEdit}
        id={id}
        delivery={deliveryQuery.data}
        navigate={navigate}
        queryClient={queryClient}
      />
    )
  }

  // In edit mode, salesOrderIds derives from deliveryQuery.data, so deliveryQuery.data is
  // already guaranteed loaded here — this is just the type-narrowing companion to it. Waits for
  // every selected/linked Sales Order to have loaded, not just the first.
  if (salesOrders.length === 0 || salesOrders.length !== salesOrderIds.length || (isEdit && !deliveryQuery.data)) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <DeliveryForm
      key={isEdit ? id : Array.from(selectedSalesOrderIds).sort().join(',')}
      salesOrders={salesOrders}
      delivery={deliveryQuery.data}
      isEdit={isEdit}
      id={id}
      salesOrderIds={salesOrderIds}
      navigate={navigate}
      queryClient={queryClient}
    />
  )
}

/**
 * Only mounts once every selected/linked Sales Order (and, in edit mode, the Delivery itself)
 * has already loaded — so useForm's defaultValues can be computed directly from real data on
 * first render. Earlier versions tried to populate these values via a form.reset() effect firing
 * after an async fetch resolved; that raced against react-hook-form's own field registration
 * (Terms of Payment intermittently ended up correct in _defaultValues but not in _formValues)
 * across multiple production-verified attempts. Mounting fresh with the right defaultValues from
 * the start sidesteps that class of bug entirely — remounted via `key` if the user picks a
 * different set of Sales Orders.
 */
function DeliveryForm({
  salesOrders,
  delivery,
  isEdit,
  id,
  salesOrderIds,
  navigate,
  queryClient,
}: {
  salesOrders: SalesOrder[]
  delivery: Delivery | undefined
  isEdit: boolean
  id: string | undefined
  salesOrderIds: string[]
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []
  const termsOfPayment = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })
  const termsOfPaymentOptions = termsOfPayment.data?.map((top) => ({ value: top.id, label: `${top.name} (${top.code})` })) ?? []

  // Every Sales Order line across every selected/linked order, flattened into one combined
  // table — the multi-source analog of a single Sales Order's own .items list. sales_order_item_id
  // is already globally unique, so no per-source tagging is needed to tell rows apart.
  const allSoItems = useMemo(() => salesOrders.flatMap((so) => so.items), [salesOrders])

  const existingQtyBySoItemId = useMemo(
    () => new Map((delivery?.items ?? []).map((line) => [line.sales_order_item_id, line.qty])),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  )

  const form = useForm<DeliveryEditorValues>({
    resolver: zodResolver(deliveryFormSchema),
    defaultValues: {
      warehouse_id: delivery?.warehouse_id ?? '',
      delivery_date: delivery?.delivery_date ?? '',
      due_date: delivery?.due_date ?? '',
      terms_of_payment_id: isEdit ? (delivery?.terms_of_payment_id ?? '') : (salesOrders[0]?.customer?.terms_of_payment_id ?? ''),
      remarks: isEdit ? (delivery?.remarks ?? '') : (salesOrders[0]?.remarks ?? ''),
      fleet: delivery?.fleet ?? '',
      driver: delivery?.driver ?? '',
      items: allSoItems.map((soItem) => ({
        sales_order_item_id: soItem.id,
        item_id: soItem.item_id,
        item_code: soItem.item_code ?? '',
        item_name: soItem.item_name ?? '',
        rate: Number(soItem.rate),
        ordered: soItem.qty,
        alreadyDelivered: soItem.delivered_qty,
        remaining: soItem.outstanding_qty,
        availableStock: 0,
        uom: soItem.uom ?? '',
        uomFactor: Number(soItem.uom_factor ?? 1),
        deliverNow: String(existingQtyBySoItemId.get(soItem.id) ?? 0),
      })),
    },
  })

  const warehouseId = form.watch('warehouse_id')

  const itemIds = useMemo(() => allSoItems.map((line) => line.item_id), [allSoItems])

  // Available Stock is warehouse-scoped, so it can only be known once a warehouse is chosen — refetches whenever the warehouse selection changes.
  const stockBalancesQuery = useQuery({
    queryKey: ['stock-balances', warehouseId, itemIds],
    queryFn: () => fetchStockBalances({ warehouse_id: warehouseId, item_ids: itemIds }),
    enabled: !!warehouseId && itemIds.length > 0,
  })

  // Fills in availableStock per row once the balance lookup resolves — deliberately separate from the form's initial values so changing the warehouse never wipes Deliver Now quantities the user already entered.
  useEffect(() => {
    const balances = stockBalancesQuery.data
    if (!balances) return

    allSoItems.forEach((soItem, index) => {
      form.setValue(`items.${index}.availableStock`, balances[soItem.item_id] ?? 0, { shouldValidate: true })
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stockBalancesQuery.data])

  const watchedTopId = form.watch('terms_of_payment_id')
  const watchedDeliveryDate = form.watch('delivery_date')

  // Recomputes Due Date whenever the Terms of Payment or Delivery Date changes — Due Date
  // itself is never a dependency here, so a manual edit to it holds until the user touches
  // one of these two inputs again. Seeded with the mounted values so the *first* time the
  // Terms of Payment lookup finishes loading (a data-arrival dependency change, not a user
  // change) doesn't read as "changed" and silently stomp a saved/manually-set Due Date.
  const lastRecomputedForRef = useRef({ topId: watchedTopId, deliveryDate: watchedDeliveryDate })
  useEffect(() => {
    if (!watchedTopId || !watchedDeliveryDate) return

    const top = termsOfPayment.data?.find((t) => t.id === watchedTopId)
    if (!top) return

    const last = lastRecomputedForRef.current
    if (last.topId === watchedTopId && last.deliveryDate === watchedDeliveryDate) return
    lastRecomputedForRef.current = { topId: watchedTopId, deliveryDate: watchedDeliveryDate }

    form.setValue('due_date', addDays(watchedDeliveryDate, top.days), { shouldValidate: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [watchedTopId, watchedDeliveryDate, termsOfPayment.data])

  const toItemsPayload = (values: DeliveryEditorValues) =>
    values.items
      .filter((line) => Number(line.deliverNow) > 0)
      .map((line) => ({ sales_order_item_id: line.sales_order_item_id, qty: Number(line.deliverNow) }))

  const saveMutation = useMutation({
    mutationFn: (values: DeliveryEditorValues) => {
      const items = toItemsPayload(values)

      if (isEdit) {
        return updateDelivery(id!, {
          warehouse_id: values.warehouse_id,
          delivery_date: values.delivery_date,
          due_date: values.due_date,
          terms_of_payment_id: values.terms_of_payment_id || null,
          remarks: values.remarks || null,
          fleet: values.fleet || null,
          driver: values.driver || null,
          items,
        })
      }

      return createDelivery({
        sales_order_ids: salesOrderIds,
        warehouse_id: values.warehouse_id,
        delivery_date: values.delivery_date,
        due_date: values.due_date,
        terms_of_payment_id: values.terms_of_payment_id || null,
        remarks: values.remarks || null,
        fleet: values.fleet || null,
        driver: values.driver || null,
        items,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['deliveries'] })
      toast.success(isEdit ? 'Delivery details updated.' : 'Delivery recorded. Confirm to update stock and create the receivable.')
      navigate('/sales/deliveries')
    },
    onError: (error) => toastApiError(error),
  })

  const completeMutation = useMutation({
    mutationFn: () => completeDelivery(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['deliveries'] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast.success('Delivery confirmed — stock updated.')
      navigate('/sales/deliveries')
    },
    onError: (error) => toastApiError(error),
  })

  const watchedItems = form.watch('items')
  const deliveringNowLines = (watchedItems ?? []).map((line) => ({ qty: line.deliverNow, rate: line.rate }))
  const subtotal = computeSubtotal(deliveringNowLines)
  // Discount is derived from each line's own Sales Order line (never entered here) — see
  // allocateSoLineDiscount()'s own docblock for the allocation rule. Preview only;
  // DeliveryService::buildDeliveryLineAttributes() on the backend is authoritative.
  const discount = (watchedItems ?? []).reduce((sum, line) => {
    const soItem = allSoItems.find((item) => item.id === line.sales_order_item_id)
    if (!soItem) return sum

    return sum + allocateSoLineDiscount(soItem, Number(line.deliverNow || 0)).discount_amount
  }, 0)
  // Tax is per-line now — each line's tax comes from its own Sales Order line (already
  // resolved there), recomputed against this delivery's own (possibly partial) net amount, not
  // a single document-wide rate. Preview only; DeliveryResource on the backend is authoritative.
  const tax = (watchedItems ?? []).reduce((sum, line) => {
    const soItem = allSoItems.find((item) => item.id === line.sales_order_item_id)
    if (!soItem) return sum

    const { net_amount: netAmount } = allocateSoLineDiscount(soItem, Number(line.deliverNow || 0))

    return sum + lineTaxAmount(netAmount, soItem.tax)
  }, 0)
  const grandTotal = subtotal - discount + tax

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${delivery?.document_number ?? 'Delivery'}` : 'New Delivery'}
        description={`Delivering against ${salesOrders.map((so) => so.document_number).join(', ')} — ${salesOrders[0]?.customer?.customer_name ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Delivery Details</CardTitle>
              <StatusBadge status={isEdit ? (delivery?.status ?? 'pending') : 'pending'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Sales Order{salesOrders.length > 1 ? 's' : ''}</span>
                <span className="text-sm font-medium">
                  {salesOrders.map((so) => so.document_number).join(', ')} — {salesOrders[0]?.customer?.customer_name}
                </span>
              </div>
              <FormField
                control={form.control}
                name="warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Location</FormLabel>
                    <SearchableSelect
                      options={warehouseOptions}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={warehouses.isLoading}
                      clearable={false}
                      placeholder="Select location"
                      aria-label="Location"
                    />
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
                name="delivery_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Delivery Date</FormLabel>
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
                name="fleet"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Fleet</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="driver"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Driver</FormLabel>
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
              {!warehouseId && (
                <p className="mb-3 text-sm text-muted-foreground">Select a location to see available stock for each item.</p>
              )}
              <DeliveryLineItemTable form={form} />
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

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && delivery?.status === 'pending'
              ? 'Recording a Delivery captures what is about to leave the warehouse. Confirming updates stock levels and creates the receivable from your customer.'
              : 'Recording quantities here doesn’t move stock yet — you’ll confirm the delivery on the next screen.'}
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/sales/deliveries')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Record Delivery
            </Button>
            {isEdit && delivery?.status === 'pending' && (
              <Button type="button" onClick={() => completeMutation.mutate()} disabled={completeMutation.isPending}>
                {completeMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Delivery
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}

/**
 * Standalone/direct delivery (no source Sales Order) — mirrors DeliveryForm's structure, but the
 * Customer is picked directly (no Sales Order to derive it from) and line items are typed
 * manually via DirectDeliveryLineItemTable instead of pre-populated from SO lines. Same
 * create/edit-while-Pending split as DeliveryForm; a Complete Direct Delivery routes to
 * DeliveryCompleteEditPage like any other, same as the outer component's existing guard.
 */
function DirectDeliveryForm({
  isEdit,
  id,
  delivery,
  navigate,
  queryClient,
}: {
  isEdit: boolean
  id: string | undefined
  delivery: Delivery | undefined
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []
  const termsOfPayment = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })
  const termsOfPaymentOptions = termsOfPayment.data?.map((top) => ({ value: top.id, label: `${top.name} (${top.code})` })) ?? []
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  const activeSalesTaxOptions = (taxesQuery.data ?? []).filter((t) => t.is_active && t.transaction_type === 'sales')

  const [selectedCustomerOption, setSelectedCustomerOption] = useState<SearchableSelectOption<Customer> | undefined>(undefined)
  const loadCustomerOptions = async (query: string) => {
    const customers = await searchCustomersLookup(query)
    return customers.map((customer) => ({ value: customer.id, label: `${customer.customer_code} — ${customer.customer_name}`, data: customer }))
  }
  const customerSelectedOption: SearchableSelectOption<Customer> | undefined = delivery?.customer
    ? { value: delivery.customer.id, label: delivery.customer.customer_name, data: delivery.customer as Customer }
    : selectedCustomerOption

  const form = useForm<DirectDeliveryEditorValues>({
    resolver: zodResolver(directDeliveryFormSchema),
    defaultValues: {
      customer_id: delivery?.customer_id ?? '',
      warehouse_id: delivery?.warehouse_id ?? '',
      delivery_date: delivery?.delivery_date ?? '',
      due_date: delivery?.due_date ?? '',
      terms_of_payment_id: delivery?.terms_of_payment_id ?? '',
      remarks: delivery?.remarks ?? '',
      fleet: delivery?.fleet ?? '',
      driver: delivery?.driver ?? '',
      items: (delivery?.items ?? []).map((line) => ({
        item_id: line.item_id,
        item_code: line.item_code,
        item_name: line.item_name,
        item_uom: line.uom,
        qtyCategory: 'unit',
        qty: String(line.qty),
        rate: String(line.rate),
        discount_type: line.discount_type ?? 'amount',
        discount_value: String(line.discount_value ?? 0),
        tax_id: line.tax_id ?? '',
      })),
    },
  })

  const saveMutation = useMutation({
    mutationFn: (values: DirectDeliveryEditorValues) => {
      const items = values.items.map((line) => ({
        item_id: line.item_id,
        qty: parseLocaleQty(line.qty),
        rate: Number(line.rate),
        discount_type: line.discount_type,
        discount_value: Number(line.discount_value) || 0,
        tax_id: line.tax_id || null,
      }))

      if (isEdit) {
        return updateDelivery(id!, {
          customer_id: values.customer_id,
          warehouse_id: values.warehouse_id,
          delivery_date: values.delivery_date,
          due_date: values.due_date,
          terms_of_payment_id: values.terms_of_payment_id || null,
          remarks: values.remarks || null,
          fleet: values.fleet || null,
          driver: values.driver || null,
          items,
        })
      }

      return createDelivery({
        customer_id: values.customer_id,
        warehouse_id: values.warehouse_id,
        delivery_date: values.delivery_date,
        due_date: values.due_date,
        terms_of_payment_id: values.terms_of_payment_id || null,
        remarks: values.remarks || null,
        fleet: values.fleet || null,
        driver: values.driver || null,
        items,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['deliveries'] })
      toast.success(isEdit ? 'Delivery details updated.' : 'Delivery recorded. Confirm to update stock and create the receivable.')
      navigate('/sales/deliveries')
    },
    onError: (error) => toastApiError(error),
  })

  const completeMutation = useMutation({
    mutationFn: () => completeDelivery(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['deliveries'] })
      toast.success('Delivery confirmed — stock updated.')
      navigate('/sales/deliveries')
    },
    onError: (error) => toastApiError(error),
  })

  const watchedItems = form.watch('items')
  const subtotal = computeSubtotal(watchedItems ?? [])
  const discount = computeTotalDiscount(watchedItems ?? [])
  const tax = (watchedItems ?? []).reduce((sum, line) => {
    const taxRecord = activeSalesTaxOptions.find((t) => t.id === line.tax_id)
    const grossAmount = Number(parseLocaleQty(line.qty || '0')) * Number(line.rate || 0)

    return sum + lineTaxAmount(grossAmount - lineDiscountAmount(grossAmount, line), taxRecord)
  }, 0)
  const grandTotal = subtotal - discount + tax

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${delivery?.document_number ?? 'Delivery'}` : 'New Direct Delivery'}
        description="Delivery with no source Sales Order — pick the customer and enter items directly."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Delivery Details</CardTitle>
              <StatusBadge status={isEdit ? (delivery?.status ?? 'pending') : 'pending'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="customer_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Customer</FormLabel>
                    <SearchableSelect
                      loadOptions={loadCustomerOptions}
                      selectedOption={customerSelectedOption}
                      value={field.value}
                      onChange={(value, option) => {
                        field.onChange(value ?? '')
                        setSelectedCustomerOption(option)
                      }}
                      clearable={false}
                      placeholder="Select customer"
                      aria-label="Customer"
                    />
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Location</FormLabel>
                    <SearchableSelect
                      options={warehouseOptions}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={warehouses.isLoading}
                      clearable={false}
                      placeholder="Select location"
                      aria-label="Location"
                    />
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
                name="delivery_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Delivery Date</FormLabel>
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
                name="fleet"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Fleet</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="driver"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Driver</FormLabel>
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
              <DirectDeliveryLineItemTable form={form} taxes={activeSalesTaxOptions} />
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

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && delivery?.status === 'pending'
              ? 'Recording a Delivery captures what is about to leave the warehouse. Confirming updates stock levels and creates the receivable from your customer.'
              : 'Recording quantities here doesn’t move stock yet — you’ll confirm the delivery on the next screen.'}
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/sales/deliveries')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Record Delivery
            </Button>
            {isEdit && delivery?.status === 'pending' && (
              <Button type="button" onClick={() => completeMutation.mutate()} disabled={completeMutation.isPending}>
                {completeMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Delivery
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
