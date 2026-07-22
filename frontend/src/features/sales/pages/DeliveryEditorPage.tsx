import { useEffect, useMemo, useState } from 'react'
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
import { formatCurrency } from '@/lib/utils'
import { computeGrandTotal, computeSubtotal, computeTax } from '@/shared/lib/documentTotals'
import { fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { fetchStockBalances } from '@/features/inventory/api/stockApi'
import { fetchDelivery, createDelivery, updateDelivery, submitDelivery } from '../api/deliveryApi'
import { fetchSalesOrder, fetchSalesOrders } from '../api/salesOrderApi'
import { DeliveryLineItemTable } from '../components/DeliveryLineItemTable'
import { deliveryFormSchema, type DeliveryEditorValues } from '../lib/deliveryFormSchema'

const emptyValues: DeliveryEditorValues = {
  warehouse_id: '',
  delivery_date: '',
  due_date: '',
  remarks: '',
  items: [],
}

export function DeliveryEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selectedSalesOrderId, setSelectedSalesOrderId] = useState<string | null>(null)

  const deliveryQuery = useQuery({
    queryKey: ['deliveries', id],
    queryFn: () => fetchDelivery(id!),
    enabled: isEdit,
  })

  const salesOrderId = isEdit ? deliveryQuery.data?.sales_order_id : (selectedSalesOrderId ?? undefined)

  // Eligible = submitted and not fully delivered. Fetched only in create mode, before an SO is picked.
  const eligibleOrdersQuery = useQuery({
    queryKey: ['sales-orders-eligible-for-delivery'],
    queryFn: () => fetchSalesOrders({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit,
  })
  const eligibleOrders = (eligibleOrdersQuery.data?.data ?? []).filter((so) => !so.is_fully_delivered)

  // Always re-fetched fresh (not reused from the eligible-list cache) so outstanding quantities are current at the moment of delivering.
  const salesOrderQuery = useQuery({
    queryKey: ['sales-orders', salesOrderId],
    queryFn: () => fetchSalesOrder(salesOrderId!),
    enabled: !!salesOrderId,
  })

  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })

  const form = useForm<DeliveryEditorValues>({
    resolver: zodResolver(deliveryFormSchema),
    defaultValues: emptyValues,
  })

  const warehouseId = form.watch('warehouse_id')

  const itemIds = useMemo(() => (salesOrderQuery.data?.items ?? []).map((line) => line.item_id), [salesOrderQuery.data])

  // Available Stock is warehouse-scoped, so it can only be known once a warehouse is chosen — refetches whenever the warehouse selection changes.
  const stockBalancesQuery = useQuery({
    queryKey: ['stock-balances', warehouseId, itemIds],
    queryFn: () => fetchStockBalances({ warehouse_id: warehouseId, item_ids: itemIds }),
    enabled: !!warehouseId && itemIds.length > 0,
  })

  useEffect(() => {
    const delivery = deliveryQuery.data
    if (!delivery) return

    if (delivery.status !== 'draft') {
      toast.error('Only draft deliveries can be edited.')
      navigate(`/sales/deliveries/${delivery.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deliveryQuery.data])

  // Establishes the fixed row set from the Sales Order — availableStock starts at 0 (unknown until a warehouse is picked) and is filled in by the effect below, without disturbing anything the user has already typed.
  useEffect(() => {
    const salesOrder = salesOrderQuery.data
    if (!salesOrder) return

    const existingQtyBySoItemId = new Map((deliveryQuery.data?.items ?? []).map((line) => [line.sales_order_item_id, line.qty]))

    form.reset({
      warehouse_id: deliveryQuery.data?.warehouse_id ?? '',
      delivery_date: deliveryQuery.data?.delivery_date ?? '',
      due_date: deliveryQuery.data?.due_date ?? '',
      remarks: deliveryQuery.data?.remarks ?? '',
      items: salesOrder.items.map((soItem) => ({
        sales_order_item_id: soItem.id,
        item_id: soItem.item_id,
        item_code: soItem.item_code ?? '',
        item_name: soItem.item_name ?? '',
        rate: Number(soItem.rate),
        ordered: soItem.qty,
        alreadyDelivered: soItem.delivered_qty,
        remaining: soItem.outstanding_qty,
        availableStock: 0,
        deliverNow: String(existingQtyBySoItemId.get(soItem.id) ?? 0),
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [salesOrderQuery.data, deliveryQuery.data])

  // Fills in availableStock per row once the balance lookup resolves — deliberately separate from the reset above so changing the warehouse never wipes Deliver Now quantities the user already entered.
  useEffect(() => {
    const salesOrder = salesOrderQuery.data
    const balances = stockBalancesQuery.data
    if (!salesOrder || !balances) return

    salesOrder.items.forEach((soItem, index) => {
      form.setValue(`items.${index}.availableStock`, balances[soItem.item_id] ?? 0, { shouldValidate: true })
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stockBalancesQuery.data])

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
          remarks: values.remarks || null,
          items,
        })
      }

      return createDelivery({
        sales_order_id: salesOrderId!,
        warehouse_id: values.warehouse_id,
        delivery_date: values.delivery_date,
        due_date: values.due_date,
        remarks: values.remarks || null,
        items,
      })
    },
    onSuccess: (delivery) => {
      queryClient.invalidateQueries({ queryKey: ['deliveries'] })
      toast.success(isEdit ? 'Delivery details updated.' : 'Delivery recorded. Confirm to update stock and create the receivable.')
      if (!isEdit) {
        navigate(`/sales/deliveries/${delivery.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitDelivery(id!),
    onSuccess: (delivery) => {
      queryClient.invalidateQueries({ queryKey: ['deliveries'] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast.success('Delivery confirmed — stock updated.')
      navigate(`/sales/deliveries/${delivery.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const watchedItems = form.watch('items')
  const deliveringNowLines = (watchedItems ?? []).map((line) => ({ qty: line.deliverNow, rate: line.rate }))
  const subtotal = computeSubtotal(deliveringNowLines)
  const tax = computeTax()
  const grandTotal = computeGrandTotal(deliveringNowLines)

  if (isEdit && deliveryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): pick the Sales Order this delivery originates from.
  if (!isEdit && !selectedSalesOrderId) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Delivery" description="Every Delivery starts from an existing, submitted Sales Order." />
        <Card>
          <CardHeader>
            <CardTitle>Select Sales Order</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <Select value="" onValueChange={setSelectedSalesOrderId} disabled={eligibleOrdersQuery.isLoading}>
              <SelectTrigger className="w-full sm:w-96">
                <SelectValue
                  placeholder={
                    eligibleOrdersQuery.isLoading
                      ? 'Loading…'
                      : eligibleOrders.length === 0
                        ? 'No sales orders with outstanding items'
                        : 'Select sales order'
                  }
                />
              </SelectTrigger>
              <SelectContent>
                {eligibleOrders.map((so) => (
                  <SelectItem key={so.id} value={so.id}>
                    {so.document_number} — {so.customer?.customer_name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              Only submitted sales orders with outstanding (not yet fully delivered) items are shown.
            </p>
            <Button type="button" variant="outline" className="self-start" onClick={() => navigate('/sales/deliveries')}>
              Cancel
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const salesOrder = salesOrderQuery.data

  if (!salesOrder) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${deliveryQuery.data?.document_number ?? 'Delivery'}` : 'New Delivery'}
        description={`Delivering against ${salesOrder.document_number} — ${salesOrder.customer?.customer_name ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Delivery Details</CardTitle>
              <StatusBadge status={isEdit ? (deliveryQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Sales Order</span>
                <span className="text-sm font-medium">
                  {salesOrder.document_number} — {salesOrder.customer?.customer_name}
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
                <p className="mb-3 text-sm text-muted-foreground">Select a warehouse to see available stock for each item.</p>
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
            {isEdit && deliveryQuery.data?.status === 'draft'
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
            {isEdit && deliveryQuery.data?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Delivery
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
