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
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { fetchSuppliersLookup } from '@/features/master/api/lookupsApi'
import { createPaymentEntry, fetchPaymentEntry, submitPaymentEntry, updatePaymentEntry } from '../api/paymentEntryApi'
import { OutstandingPayableSelect } from '../components/OutstandingPayableSelect'
import { paymentEntryFormSchema, type PaymentEntryEditorValues } from '../lib/paymentEntryFormSchema'
import { PAYMENT_METHOD_OPTIONS } from '../lib/paymentMethodLabels'
import type { AccountsPayable, PaymentMethod } from '../types'

const emptyValues: PaymentEntryEditorValues = {
  supplier_id: '',
  accounts_payable_id: '',
  outstandingAmount: 0,
  amount: '',
  payment_date: '',
  payment_method: '',
  reference_number: '',
  remarks: '',
}

export function OutgoingPaymentEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selectedPayable, setSelectedPayable] = useState<AccountsPayable | null>(null)

  const paymentQuery = useQuery({
    queryKey: ['payment-entries', id],
    queryFn: () => fetchPaymentEntry(id!),
    enabled: isEdit,
  })

  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup })

  const form = useForm<PaymentEntryEditorValues>({
    resolver: zodResolver(paymentEntryFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    const payment = paymentQuery.data
    if (!payment) return

    if (payment.status !== 'draft') {
      toast.error('Only draft payments can be edited.')
      navigate(`/finance/outgoing/${payment.id}`, { replace: true })
      return
    }

    const line = payment.items[0]
    setSelectedPayable(line?.accounts_payable ?? null)
    form.reset({
      supplier_id: payment.supplier_id,
      accounts_payable_id: line?.accounts_payable_id ?? '',
      outstandingAmount: line ? Number(line.accounts_payable.outstanding_amount) : 0,
      amount: line ? String(line.paid_amount) : '',
      payment_date: payment.payment_date,
      payment_method: payment.payment_method,
      reference_number: payment.reference_number ?? '',
      remarks: payment.remarks ?? '',
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paymentQuery.data])

  const saveMutation = useMutation({
    mutationFn: (values: PaymentEntryEditorValues) => {
      const payload = {
        supplier_id: values.supplier_id,
        payment_date: values.payment_date,
        payment_method: values.payment_method as PaymentMethod,
        reference_number: values.reference_number || null,
        remarks: values.remarks || null,
        items: [{ accounts_payable_id: values.accounts_payable_id, paid_amount: Number(values.amount) }],
      }

      return isEdit ? updatePaymentEntry(id!, payload) : createPaymentEntry(payload)
    },
    onSuccess: (payment) => {
      queryClient.invalidateQueries({ queryKey: ['payment-entries'] })
      toast.success(isEdit ? 'Payment details updated.' : 'Payment saved as draft.')
      navigate(`/finance/outgoing/${payment.id}/edit`, { replace: true })
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitPaymentEntry(id!),
    onSuccess: (payment) => {
      queryClient.invalidateQueries({ queryKey: ['payment-entries'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Payment confirmed — payable updated.')
      navigate(`/finance/outgoing/${payment.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const supplierId = form.watch('supplier_id')

  if (isEdit && paymentQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${paymentQuery.data?.document_number ?? 'Payment'}` : 'New Outgoing Payment'}
        description="Record a payment made to a supplier, settling one outstanding Purchase transaction."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Payment Details</CardTitle>
              <StatusBadge status={isEdit ? (paymentQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="supplier_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Supplier</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={(next) => {
                        field.onChange(next)
                        form.setValue('accounts_payable_id', '')
                        form.setValue('outstandingAmount', 0)
                        form.setValue('amount', '')
                        setSelectedPayable(null)
                      }}
                    >
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
                name="accounts_payable_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Source Document</FormLabel>
                    <FormControl>
                      <OutstandingPayableSelect
                        supplierId={supplierId || null}
                        value={field.value || null}
                        onChange={(ap) => {
                          field.onChange(ap.id)
                          form.setValue('outstandingAmount', Number(ap.outstanding_amount))
                          form.setValue('amount', String(ap.outstanding_amount))
                          setSelectedPayable(ap)
                        }}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="payment_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Payment Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="payment_method"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Payment Method</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select payment method" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {PAYMENT_METHOD_OPTIONS.map(([value, label]) => (
                          <SelectItem key={value} value={value}>
                            {label}
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
                name="amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Amount</FormLabel>
                    <FormControl>
                      <Input type="number" step="0.01" placeholder="0" {...field} />
                    </FormControl>
                    {selectedPayable && (
                      <p className="text-xs text-muted-foreground">
                        Max: {formatCurrency(selectedPayable.outstanding_amount)}
                      </p>
                    )}
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reference_number"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reference Number</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional — e.g. bank transfer ref." {...field} />
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

          {selectedPayable && (
            <Card>
              <CardHeader>
                <CardTitle>Purchase Summary</CardTitle>
              </CardHeader>
              <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">Grand Total</span>
                  <span className="text-sm font-medium">{formatCurrency(selectedPayable.amount)}</span>
                </div>
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">Paid Amount</span>
                  <span className="text-sm font-medium">{formatCurrency(selectedPayable.paid_amount)}</span>
                </div>
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">Outstanding</span>
                  <span className="text-sm font-medium">{formatCurrency(selectedPayable.outstanding_amount)}</span>
                </div>
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">Payment Status</span>
                  <StatusBadge status={selectedPayable.status} />
                </div>
              </CardContent>
            </Card>
          )}

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && paymentQuery.data?.status === 'draft'
              ? 'Saving records the payment. Confirming settles it against the payable — this cannot be undone.'
              : 'Saving records the payment as a draft — nothing is settled until you confirm it.'}
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/finance/outgoing')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && paymentQuery.data?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Payment
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
