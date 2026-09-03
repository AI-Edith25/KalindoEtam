import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, Loader2, Save, Send } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { computeSubtotal, computeLineTaxTotal } from '@/shared/lib/documentTotals'
import {
  fetchBranches,
  fetchCustomersLookup,
  fetchItemsLookup,
  fetchSalesPersonsLookup,
  fetchTaxesLookup,
  fetchTermsOfPaymentLookup,
  fetchWarehousesLookup,
} from '@/features/master/api/lookupsApi'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { approveSalesOrder, createSalesOrder, fetchSalesOrder, updateSalesOrder } from '../api/salesOrderApi'
import { useCustomerCreditCheck } from '../hooks/useCustomerCreditCheck'
import { SalesOrderLineItemTable } from '../components/SalesOrderLineItemTable'
import { emptySalesOrderEditorValues, salesOrderFormSchema, type SalesOrderEditorValues } from '../lib/salesOrderFormSchema'
import { ApprovalPanel } from '@/features/approval/components/ApprovalPanel'
import type { SalesOrderFormValues } from '../types'

const APPROVABLE_TYPE = 'App\\Models\\SalesOrder'
const NONE = '__none__'

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
  const salesPersons = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const termsOfPayment = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })

  const form = useForm<SalesOrderEditorValues>({
    resolver: zodResolver(salesOrderFormSchema),
    defaultValues: emptySalesOrderEditorValues,
  })

  // Items are re-fetched whenever the order's Warehouse changes, so each item's effective_rate
  // reflects the resolved warehouse override (falls back to standard_rate with none selected)
  // — see ItemController::index, ItemPriceResolver, and SalesOrderLineItemTable's handleItemChange.
  const selectedWarehouseId = form.watch('warehouse_id') || undefined
  const items = useQuery({
    queryKey: ['items-lookup', selectedWarehouseId],
    queryFn: () => fetchItemsLookup(selectedWarehouseId),
  })

  useEffect(() => {
    const order = orderQuery.data
    if (!order) return

    if (order.status !== 'submitted') {
      toast.error('Only sales orders awaiting approval can be edited.')
      navigate(`/sales/orders/${order.id}`, { replace: true })
      return
    }

    form.reset({
      customer_id: order.customer_id,
      sales_person_id: order.sales_person_id ?? '',
      branch_id: order.branch_id ?? '',
      warehouse_id: order.warehouse_id ?? '',
      order_date: order.order_date,
      expected_delivery_date: order.expected_delivery_date ?? '',
      remarks: order.remarks ?? '',
      attention: order.attention ?? '',
      tel: order.tel ?? '',
      fax: order.fax ?? '',
      reference: order.reference ?? '',
      terms_of_payment_id: order.terms_of_payment_id ?? '',
      // No header override on Sales Order anymore — tax is per-line only, so this field is
      // never re-loaded from the order's own (now-legacy) header tax_id; it stays empty and
      // toPayload() below sends null, clearing any stale value on save.
      tax_id: '',
      items: order.items.map((line) => ({
        item_id: line.item_id,
        qty: String(line.qty),
        rate: String(line.rate),
        tax_id: line.tax_id ?? '',
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orderQuery.data])

  // New order only — default to the head-office branch, matching the ticket's
  // "default ke cabang utama" (single-branch companies never need to touch this field).
  useEffect(() => {
    if (isEdit || !branches.data?.length) return
    if (form.getValues('branch_id')) return

    const headOffice = branches.data.find((branch) => branch.is_head_office) ?? branches.data[0]
    form.setValue('branch_id', headOffice.id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branches.data, isEdit])

  // New order only — default to the Main warehouse, same "don't make every order pick the
  // obvious default" reasoning as the branch default above.
  useEffect(() => {
    if (isEdit || !warehouses.data?.length) return
    if (form.getValues('warehouse_id')) return

    const main = warehouses.data.find((w) => w.warehouse_type === 'main') ?? warehouses.data[0]
    form.setValue('warehouse_id', main.id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [warehouses.data, isEdit])

  // Prefills Tel from the selected customer's master phone — only Customer.phone exists on the
  // master (no attn/fax there), so those two stay plain manual input. Only fills an empty Tel, so
  // it never stomps a value the user already typed or loaded from a saved order.
  const customerIdForPrefill = form.watch('customer_id')
  useEffect(() => {
    if (!customerIdForPrefill || form.getValues('tel')) return

    const customer = customers.data?.find((c) => c.id === customerIdForPrefill)
    if (customer?.phone) form.setValue('tel', customer.phone)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerIdForPrefill, customers.data])

  const toPayload = (values: SalesOrderEditorValues): SalesOrderFormValues => ({
    customer_id: values.customer_id,
    sales_person_id: values.sales_person_id || null,
    branch_id: values.branch_id,
    warehouse_id: values.warehouse_id,
    order_date: values.order_date,
    expected_delivery_date: values.expected_delivery_date || null,
    remarks: values.remarks || null,
    attention: values.attention || null,
    tel: values.tel || null,
    fax: values.fax || null,
    reference: values.reference || null,
    terms_of_payment_id: values.terms_of_payment_id || null,
    tax_id: values.tax_id || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      qty: Number(line.qty),
      rate: Number(line.rate),
      tax_id: line.tax_id || null,
    })),
    ...(values.override_credit_block ? { override_credit_block: true, override_reason: values.override_reason || null } : {}),
  })

  const saveMutation = useMutation({
    mutationFn: (values: SalesOrderEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateSalesOrder(id!, payload) : createSalesOrder(payload)
    },
    onSuccess: (order) => {
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast.success(isEdit ? 'Sales Order updated.' : 'Sales Order saved.')
      if (!isEdit) {
        navigate(`/sales/orders/${order.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const approveMutation = useMutation({
    mutationFn: () =>
      approveSalesOrder(
        id!,
        overrideChecked ? { override_credit_block: true, override_reason: form.getValues('override_reason') || null } : undefined,
      ),
    onSuccess: (order) => {
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast.success('Sales Order approved.')
      navigate(`/sales/orders/${order.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales-orders'] })

  // Same gate as SalesOrderDetailPage's own Submit button — approval must be APPROVED (or not
  // required) before Submit can succeed. See docs/APPROVAL_WORKFLOW_DESIGN.md §2.
  const blockedByApproval = orderQuery.data?.requires_approval && orderQuery.data?.latest_approval?.status !== 'approved'

  const watchedItems = form.watch('items')
  const subtotal = computeSubtotal(watchedItems ?? [])

  // Tax is per-line now, defaulting from each line's Item — no header override on Sales Order.
  // Preview only; TaxService::calculate() on the backend always computes and returns the
  // authoritative per-line tax_amount/grand_total on save.
  const activeSalesTaxOptions = (taxesQuery.data ?? []).filter((t) => t.is_active && t.transaction_type === 'sales')
  const tax = computeLineTaxTotal(watchedItems ?? [], (line) => activeSalesTaxOptions.find((t) => t.id === line.tax_id))
  const grandTotal = subtotal + tax

  // Customer Credit block — see CustomerCreditService on the backend. Live-rechecked against
  // grandTotal on every line-item change with no extra network call (see useCustomerCreditCheck).
  const watchedCustomerId = form.watch('customer_id')
  const { blocked: creditBlocked, message: creditMessage } = useCustomerCreditCheck(watchedCustomerId || undefined, grandTotal)
  const canOverrideCredit = useHasPermission('sales.orders.override_credit_check')
  const overrideChecked = form.watch('override_credit_block')
  const overrideReasonFilled = !!form.watch('override_reason')?.trim()
  const creditBlockActive = creditBlocked && !(overrideChecked && overrideReasonFilled)

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
              <StatusBadge status={isEdit ? (orderQuery.data?.status ?? 'submitted') : 'submitted'} />
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
              <FormItem>
                <FormLabel>Customer Code</FormLabel>
                <FormControl>
                  <Input value={customers.data?.find((c) => c.id === customerIdForPrefill)?.customer_code ?? ''} disabled readOnly />
                </FormControl>
              </FormItem>
              {creditBlocked && (
                <div className="flex flex-col gap-3 rounded-md border border-destructive/50 bg-destructive/5 p-3 text-sm sm:col-span-2">
                  <div className="flex items-start gap-2 text-destructive">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <span>{creditMessage}</span>
                  </div>
                  {canOverrideCredit && (
                    <div className="flex flex-col gap-2 border-t border-destructive/20 pt-3">
                      <FormField
                        control={form.control}
                        name="override_credit_block"
                        render={({ field }) => (
                          <FormItem className="flex flex-row items-center justify-between">
                            <FormLabel className="cursor-pointer font-normal">Override and continue anyway</FormLabel>
                            <FormControl>
                              <Switch checked={field.value ?? false} onCheckedChange={field.onChange} />
                            </FormControl>
                          </FormItem>
                        )}
                      />
                      {overrideChecked && (
                        <FormField
                          control={form.control}
                          name="override_reason"
                          render={({ field }) => (
                            <FormItem>
                              <FormLabel>Override Reason</FormLabel>
                              <FormControl>
                                <Textarea placeholder="Required — explain the manual approval for this exception" {...field} />
                              </FormControl>
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                      )}
                    </div>
                  )}
                </div>
              )}
              <FormField
                control={form.control}
                name="sales_person_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Sales Person</FormLabel>
                    <Select value={field.value || NONE} onValueChange={(value) => field.onChange(value === NONE ? '' : value)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={salesPersons.isLoading ? 'Loading…' : 'Select sales person'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NONE}>None</SelectItem>
                        {salesPersons.data?.map((salesPerson) => (
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
                name="branch_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Branch</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={branches.isLoading ? 'Loading…' : 'Select branch'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {branches.data?.map((branch) => (
                          <SelectItem key={branch.id} value={branch.id}>
                            {branch.name}
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
                            {warehouse.name} ({warehouse.code})
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
                name="attention"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Attn</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="tel"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tel</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="fax"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Fax</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reference"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reference</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
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
                    <FormLabel>Payment Terms</FormLabel>
                    <Select value={field.value || NONE} onValueChange={(value) => field.onChange(value === NONE ? '' : value)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={termsOfPayment.isLoading ? 'Loading…' : 'None'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NONE}>None</SelectItem>
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
              <SalesOrderLineItemTable form={form} items={items.data ?? []} itemsLoading={items.isLoading} taxes={activeSalesTaxOptions} />
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

          {isEdit && orderQuery.data?.status === 'submitted' && orderQuery.data?.requires_approval && (
            <ApprovalPanel
              approvableType={APPROVABLE_TYPE}
              approvableId={id!}
              module="sales.orders"
              documentStatus={orderQuery.data.status}
              documentLabel={orderQuery.data.document_number ?? 'this Sales Order'}
              onChanged={invalidate}
            />
          )}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/sales/orders')}>
              Cancel
            </Button>
            <Button
              type="submit"
              variant="outline"
              disabled={saveMutation.isPending || creditBlockActive}
              title={creditBlockActive ? creditMessage : undefined}
            >
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save
            </Button>
            {isEdit && orderQuery.data?.status === 'submitted' && (
              <Button
                type="button"
                onClick={() => approveMutation.mutate()}
                disabled={approveMutation.isPending || blockedByApproval || creditBlockActive}
                title={
                  creditBlockActive
                    ? creditMessage
                    : blockedByApproval
                      ? 'This order needs an approved request before it can be approved.'
                      : undefined
                }
              >
                {approveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Approve
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
