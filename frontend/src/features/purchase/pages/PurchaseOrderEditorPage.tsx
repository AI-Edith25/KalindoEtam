import { useEffect } from 'react'
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
import { getErrorMessage } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { fetchItemsLookup, fetchSuppliersLookup, fetchTaxesLookup } from '@/features/master/api/lookupsApi'
import { createPurchaseOrder, fetchPurchaseOrder, submitPurchaseOrder, updatePurchaseOrder } from '../api/purchaseOrderApi'
import { PurchaseOrderLineItemTable } from '../components/PurchaseOrderLineItemTable'
import { computeSubtotal } from '@/shared/lib/documentTotals'
import {
  emptyPurchaseOrderEditorValues,
  purchaseOrderFormSchema,
  type PurchaseOrderEditorValues,
} from '../lib/purchaseOrderFormSchema'
import type { PurchaseOrderFormValues } from '../types'

const NO_TAX = '__none__'

export function PurchaseOrderEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const orderQuery = useQuery({
    queryKey: ['purchase-orders', id],
    queryFn: () => fetchPurchaseOrder(id!),
    enabled: isEdit,
  })

  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup })
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })

  const form = useForm<PurchaseOrderEditorValues>({
    resolver: zodResolver(purchaseOrderFormSchema),
    defaultValues: emptyPurchaseOrderEditorValues,
  })

  useEffect(() => {
    const order = orderQuery.data
    if (!order) return

    if (order.status !== 'draft') {
      toast.error('Only draft purchase orders can be edited.')
      navigate(`/purchase/orders/${order.id}`, { replace: true })
      return
    }

    form.reset({
      supplier_id: order.supplier_id,
      order_date: order.order_date,
      expected_delivery_date: order.expected_delivery_date ?? '',
      tax_id: order.tax_id ?? '',
      remarks: order.remarks ?? '',
      items: order.items.map((line) => ({
        item_id: line.item_id,
        qty: String(line.qty),
        rate: String(line.rate),
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orderQuery.data])

  const toPayload = (values: PurchaseOrderEditorValues): PurchaseOrderFormValues => ({
    supplier_id: values.supplier_id,
    order_date: values.order_date,
    expected_delivery_date: values.expected_delivery_date || null,
    tax_id: values.tax_id || null,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      qty: Number(line.qty),
      rate: Number(line.rate),
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: PurchaseOrderEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updatePurchaseOrder(id!, payload) : createPurchaseOrder(payload)
    },
    onSuccess: (order) => {
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      toast.success(isEdit ? 'Purchase Order updated.' : 'Purchase Order saved as draft.')
      if (!isEdit) {
        navigate(`/purchase/orders/${order.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseOrder(id!),
    onSuccess: (order) => {
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      toast.success('Purchase Order submitted.')
      navigate(`/purchase/orders/${order.id}`)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const watchedItems = form.watch('items')
  const subtotal = computeSubtotal(watchedItems ?? [])

  const existingTax = isEdit ? orderQuery.data?.tax : null
  const activeTaxOptions = (taxesQuery.data ?? []).filter((t) => t.is_active)
  const taxOptions =
    existingTax && !activeTaxOptions.some((t) => t.id === existingTax.id) ? [...activeTaxOptions, existingTax] : activeTaxOptions

  const watchedTaxId = form.watch('tax_id')
  const selectedTax = taxOptions.find((t) => t.id === watchedTaxId) ?? null
  // Preview only — TaxService::calculate() on the backend always computes and returns the
  // authoritative tax_amount/grand_total on save. Mirrors that same exclusive-VAT formula
  // purely for instant visual feedback; the saved value never comes from here.
  const tax = selectedTax && selectedTax.type === 'vat' ? Math.round(subtotal * (Number(selectedTax.rate) / 100) * 100) / 100 : 0
  const grandTotal = subtotal + tax

  if (isEdit && orderQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${orderQuery.data?.document_number ?? 'Purchase Order'}` : 'New Purchase Order'}
        description="Order goods from a supplier for later receipt into a warehouse."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Order Details</CardTitle>
              <StatusBadge status={isEdit ? (orderQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="supplier_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Supplier</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
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
                control={form.control}
                name="order_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Order Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="expected_delivery_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Expected Delivery Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="tax_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tax</FormLabel>
                    <Select
                      value={field.value || NO_TAX}
                      onValueChange={(value) => field.onChange(value === NO_TAX ? '' : value)}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={taxesQuery.isLoading ? 'Loading…' : 'No tax'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_TAX}>No tax</SelectItem>
                        {taxOptions.map((t) => (
                          <SelectItem key={t.id} value={t.id}>
                            {t.name} ({t.code}){t.type === 'vat' ? ` — ${t.rate}%` : ''}
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
              <PurchaseOrderLineItemTable form={form} items={items.data ?? []} itemsLoading={items.isLoading} />
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
            <Button type="button" variant="outline" onClick={() => navigate('/purchase/orders')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && orderQuery.data?.status === 'draft' && (
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
