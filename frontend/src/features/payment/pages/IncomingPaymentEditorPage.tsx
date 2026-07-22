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
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchCustomersLookup } from '@/features/master/api/lookupsApi'
import { createReceiptEntry, fetchReceiptEntry, submitReceiptEntry, updateReceiptEntry } from '../api/receiptEntryApi'
import { receiptEntryFormSchema, type ReceiptEntryEditorValues } from '../lib/receiptEntryFormSchema'
import { PAYMENT_METHOD_OPTIONS } from '../lib/paymentMethodLabels'
import type { PaymentMethod } from '../types'

const emptyValues: ReceiptEntryEditorValues = {
  customer_id: '',
  total_amount: '',
  receipt_date: '',
  payment_method: '',
  reference_number: '',
  remarks: '',
}

export function IncomingPaymentEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const receiptQuery = useQuery({
    queryKey: ['receipt-entries', id],
    queryFn: () => fetchReceiptEntry(id!),
    enabled: isEdit,
  })

  const customers = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })

  const form = useForm<ReceiptEntryEditorValues>({
    resolver: zodResolver(receiptEntryFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    const receipt = receiptQuery.data
    if (!receipt) return

    if (receipt.status !== 'draft') {
      toast.error('Only draft payments can be edited.')
      navigate(`/finance/incoming/${receipt.id}`, { replace: true })
      return
    }

    form.reset({
      customer_id: receipt.customer_id,
      total_amount: String(receipt.total_amount),
      receipt_date: receipt.receipt_date,
      payment_method: receipt.payment_method,
      reference_number: receipt.reference_number ?? '',
      remarks: receipt.remarks ?? '',
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [receiptQuery.data])

  const saveMutation = useMutation({
    mutationFn: (values: ReceiptEntryEditorValues) => {
      const payload = {
        customer_id: values.customer_id,
        receipt_date: values.receipt_date,
        payment_method: values.payment_method as PaymentMethod,
        reference_number: values.reference_number || null,
        remarks: values.remarks || null,
        total_amount: Number(values.total_amount),
      }

      return isEdit ? updateReceiptEntry(id!, payload) : createReceiptEntry(payload)
    },
    onSuccess: (receipt) => {
      queryClient.invalidateQueries({ queryKey: ['receipt-entries'] })
      toast.success(isEdit ? 'Payment details updated.' : 'Payment saved as draft.')
      navigate(`/finance/incoming/${receipt.id}/edit`, { replace: true })
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitReceiptEntry(id!),
    onSuccess: (receipt) => {
      queryClient.invalidateQueries({ queryKey: ['receipt-entries'] })
      toast.success('Payment received. Allocate it to an invoice from the detail page.')
      navigate(`/finance/incoming/${receipt.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  if (isEdit && receiptQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${receiptQuery.data?.document_number ?? 'Payment'}` : 'New Incoming Payment'}
        description="Record a payment received from a customer. Allocating it to specific invoices is a separate step, once it's confirmed."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Payment Details</CardTitle>
              <StatusBadge status={isEdit ? (receiptQuery.data?.status ?? 'draft') : 'draft'} />
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
                name="total_amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Amount Received</FormLabel>
                    <FormControl>
                      <Input type="number" step="0.01" placeholder="0" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="receipt_date"
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

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && receiptQuery.data?.status === 'draft'
              ? 'Saving records the payment. Confirming marks the money as received — allocate it to an invoice afterward.'
              : 'Saving records the payment as a draft — nothing is received until you confirm it.'}
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/finance/incoming')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && receiptQuery.data?.status === 'draft' && (
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
