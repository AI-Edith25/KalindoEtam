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
import { computeGrandTotal, computeSubtotal, computeTax } from '@/shared/lib/documentTotals'
import { fetchCustomersLookup, fetchItemsLookup } from '@/features/master/api/lookupsApi'
import { createSalesOrder, fetchSalesOrder, submitSalesOrder, updateSalesOrder } from '../api/salesOrderApi'
import { SalesOrderLineItemTable } from '../components/SalesOrderLineItemTable'
import { emptySalesOrderEditorValues, salesOrderFormSchema, type SalesOrderEditorValues } from '../lib/salesOrderFormSchema'
import type { SalesOrderFormValues } from '../types'

export function SalesOrderEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const orderQuery = useQuery({
    queryKey: ['sales-orders', id],
    queryFn: () => fetchSalesOrder(id!),
    enabled: isEdit,
  })

  const customers = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup })

  const form = useForm<SalesOrderEditorValues>({
    resolver: zodResolver(salesOrderFormSchema),
    defaultValues: emptySalesOrderEditorValues,
  })

  useEffect(() => {
    const order = orderQuery.data
    if (!order) return

    if (order.status !== 'draft') {
      toast.error('Only draft sales orders can be edited.')
      navigate(`/sales/orders/${order.id}`, { replace: true })
      return
    }

    form.reset({
      customer_id: order.customer_id,
      order_date: order.order_date,
      expected_delivery_date: order.expected_delivery_date ?? '',
      remarks: order.remarks ?? '',
      items: order.items.map((line) => ({
        item_id: line.item_id,
        qty: String(line.qty),
        rate: String(line.rate),
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orderQuery.data])

  const toPayload = (values: SalesOrderEditorValues): SalesOrderFormValues => ({
    customer_id: values.customer_id,
    order_date: values.order_date,
    expected_delivery_date: values.expected_delivery_date || null,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      qty: Number(line.qty),
      rate: Number(line.rate),
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: SalesOrderEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateSalesOrder(id!, payload) : createSalesOrder(payload)
    },
    onSuccess: (order) => {
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast.success(isEdit ? 'Sales Order updated.' : 'Sales Order saved as draft.')
      if (!isEdit) {
        navigate(`/sales/orders/${order.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitSalesOrder(id!),
    onSuccess: (order) => {
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast.success('Sales Order submitted.')
      navigate(`/sales/orders/${order.id}`)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const watchedItems = form.watch('items')
  const subtotal = computeSubtotal(watchedItems ?? [])
  const tax = computeTax()
  const grandTotal = computeGrandTotal(watchedItems ?? [])

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
        title={isEdit ? `Edit ${orderQuery.data?.document_number ?? 'Sales Order'}` : 'New Sales Order'}
        description="Record a customer order. Stock is not reduced until this order is delivered."
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
                name="customer_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Customer</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={customers.isLoading ? 'Loading…' : 'Select customer'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {customers.data?.map((customer) => (
                          <SelectItem key={customer.id} value={customer.id}>
                            {customer.customer_name}
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
              <SalesOrderLineItemTable form={form} items={items.data ?? []} itemsLoading={items.isLoading} />
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
            <Button type="button" variant="outline" onClick={() => navigate('/sales/orders')}>
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
