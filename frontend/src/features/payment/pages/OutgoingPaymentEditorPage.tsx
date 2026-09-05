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
import { RupiahInput } from '@/components/shared/RupiahInput'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchBranches, fetchSuppliersLookup, fetchChartOfAccountsLookup } from '@/features/master/api/lookupsApi'
import { createPaymentEntry, fetchPaymentEntry, submitPaymentEntry, updatePaymentEntry, type PaymentEntryPayload } from '../api/paymentEntryApi'
import { fetchAccountsPayables } from '../api/accountsPayableApi'
import { allocatePaymentEntry } from '../api/paymentEntryAllocationApi'
import { OutstandingPayablesTable } from '../components/OutstandingPayablesTable'
import { paymentEntryFormSchema, type PaymentEntryEditorValues } from '../lib/paymentEntryFormSchema'
import type { PaymentEntryType } from '../types'

const emptyValues: PaymentEntryEditorValues = {
  payment_type: 'supplier',
  supplier_id: '',
  expense_account_id: '',
  description: '',
  amount: '',
  payment_date: '',
  cash_account_id: '',
  branch_id: '',
  reference_number: '',
  remarks: '',
}

export function OutgoingPaymentEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const paymentQuery = useQuery({
    queryKey: ['payment-entries', id],
    queryFn: () => fetchPaymentEntry(id!),
    enabled: isEdit,
  })

  const suppliers = useQuery({ queryKey: ['suppliers-lookup'], queryFn: fetchSuppliersLookup })
  const chartOfAccounts = useQuery({ queryKey: ['chart-of-accounts-lookup'], queryFn: fetchChartOfAccountsLookup })
  const expenseAccountOptions = chartOfAccounts.data?.filter((account) => account.account_type === 'expense') ?? []
  const cashAccountOptions = chartOfAccounts.data?.filter((account) => account.is_cash_bank) ?? []
  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })

  const form = useForm<PaymentEntryEditorValues>({
    resolver: zodResolver(paymentEntryFormSchema),
    defaultValues: emptyValues,
  })

  const supplierId = form.watch('supplier_id')
  const paymentType = form.watch('payment_type')
  const isSupplierType = paymentType === 'supplier'

  // Payable -> user-entered "To Allocate" amount. Checked and "has an entry in this
  // map" are the same fact — Amount Paid below is derived as the sum of these, not
  // typed directly, unless nothing is checked (an unapplied/advance payment). Mirrors
  // IncomingPaymentEditorPage's allocations state exactly.
  const [allocations, setAllocations] = useState<Map<string, number>>(new Map())

  const outstandingQuery = useQuery({
    queryKey: ['accounts-payables', supplierId],
    queryFn: () => fetchAccountsPayables({ supplier_id: supplierId, per_page: 100 }),
    enabled: !!supplierId && isSupplierType,
  })

  const outstandingPayables = useMemo(
    () => (outstandingQuery.data?.data ?? []).filter((ap) => ap.status !== 'paid'),
    [outstandingQuery.data],
  )

  function commitAllocations(next: Map<string, number>) {
    setAllocations(next)
    const total = Array.from(next.values()).reduce((sum, amt) => sum + amt, 0)
    const rounded = Math.round(total * 100) / 100 // avoid float drift (0.1 + 0.2 etc.)
    form.setValue('amount', rounded > 0 ? String(rounded) : '', { shouldValidate: form.formState.isSubmitted })
  }

  const togglePayable = (accountsPayableId: string, checked: boolean) => {
    const next = new Map(allocations)
    if (checked) {
      const ap = outstandingPayables.find((row) => row.id === accountsPayableId)
      next.set(accountsPayableId, ap ? Number(ap.outstanding_amount) : 0)
    } else {
      next.delete(accountsPayableId)
    }
    commitAllocations(next)
  }

  const updateAllocation = (accountsPayableId: string, amount: number) => {
    const next = new Map(allocations)
    next.set(accountsPayableId, amount)
    commitAllocations(next)
  }

  useEffect(() => {
    const payment = paymentQuery.data
    if (!payment) return

    if (payment.status !== 'draft') {
      toast.error('Only draft payments can be edited.')
      navigate(`/finance/outgoing/${payment.id}`, { replace: true })
      return
    }

    form.reset({
      payment_type: payment.payment_type,
      supplier_id: payment.supplier_id ?? '',
      expense_account_id: payment.expense_account_id ?? '',
      description: payment.description ?? '',
      amount: String(payment.total_amount),
      payment_date: payment.payment_date,
      cash_account_id: payment.cash_account_id ?? '',
      branch_id: payment.branch_id ?? '',
      reference_number: payment.reference_number ?? '',
      remarks: payment.remarks ?? '',
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paymentQuery.data])

  const saveMutation = useMutation({
    mutationFn: (values: PaymentEntryEditorValues) => {
      const payload: PaymentEntryPayload =
        values.payment_type === 'general_expense'
          ? {
              payment_type: 'general_expense',
              expense_account_id: values.expense_account_id,
              description: values.description,
              amount: Number(values.amount),
              payment_date: values.payment_date,
              cash_account_id: values.cash_account_id,
              branch_id: values.branch_id || null,
              reference_number: values.reference_number || null,
              remarks: values.remarks || null,
            }
          : {
              payment_type: 'supplier',
              supplier_id: values.supplier_id,
              amount: Number(values.amount),
              payment_date: values.payment_date,
              cash_account_id: values.cash_account_id,
              branch_id: values.branch_id || null,
              reference_number: values.reference_number || null,
              remarks: values.remarks || null,
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
    mutationFn: async () => {
      const payment = await submitPaymentEntry(id!)

      const lines = Array.from(allocations.entries())
        .filter(([, amount]) => amount > 0)
        .map(([accounts_payable_id, amount]) => ({ accounts_payable_id, amount }))
      if (lines.length === 0) {
        return { payment, allocationError: null as unknown }
      }

      // The payment is already made at this point — an allocation failure here (e.g. a
      // bill got settled elsewhere in the meantime) must not look like the whole submit
      // failed. It's reported separately; the existing "Allocate Payment" action on the
      // detail page remains available to finish it.
      try {
        await allocatePaymentEntry(payment.id, lines)
        return { payment, allocationError: null as unknown }
      } catch (error) {
        return { payment, allocationError: error }
      }
    },
    onSuccess: ({ payment, allocationError }) => {
      queryClient.invalidateQueries({ queryKey: ['payment-entries'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })

      if (allocationError) {
        toast.success('Payment confirmed.')
        toastApiError(allocationError)
      } else if (allocations.size > 0) {
        toast.success('Payment confirmed and allocated to the selected bill(s).')
      } else {
        toast.success('Payment confirmed. Allocate it to a bill from the detail page.')
      }

      navigate(`/finance/outgoing/${payment.id}`)
    },
    onError: (error) => toastApiError(error),
  })

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
        title={isEdit ? `Edit ${paymentQuery.data?.document_number ?? 'Payment'}` : 'New Payment Voucher'}
        description="Record a payment to a supplier, or a general office expense with no supplier/source document."
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
                name="payment_type"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Payment Type</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={(next) => {
                        field.onChange(next as PaymentEntryType)
                        form.setValue('supplier_id', '')
                        form.setValue('expense_account_id', '')
                        form.setValue('description', '')
                        form.setValue('amount', '')
                        commitAllocations(new Map())
                      }}
                      disabled={isEdit}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full sm:w-72">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="supplier">Against Supplier (Purchase)</SelectItem>
                        <SelectItem value="general_expense">General Expense / Office Cash</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {isSupplierType ? (
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
                          // A supplier switch invalidates any payable selection made for the
                          // previous one. Scoped to this handler (not a supplierId-watching
                          // effect) so it never fires from form.reset() restoring an existing
                          // draft's supplier_id/amount on edit-mode load.
                          commitAllocations(new Map())
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
              ) : (
                <>
                  <FormField
                    control={form.control}
                    name="expense_account_id"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Category</FormLabel>
                        <Select value={field.value} onValueChange={field.onChange}>
                          <FormControl>
                            <SelectTrigger className="w-full">
                              <SelectValue placeholder={chartOfAccounts.isLoading ? 'Loading…' : 'Select category'} />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            {expenseAccountOptions.map((account) => (
                              <SelectItem key={account.id} value={account.id}>
                                {account.name}
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
                    name="description"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Description</FormLabel>
                        <FormControl>
                          <Input placeholder="e.g. Ojek online ke kantor pajak" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </>
              )}

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
                name="cash_account_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Payment Method</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={chartOfAccounts.isLoading ? 'Loading…' : 'Select cash/bank account'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {cashAccountOptions.map((account) => (
                          <SelectItem key={account.id} value={account.id}>
                            {account.name}
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
                    <Select value={field.value || undefined} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={branches.isLoading ? 'Loading…' : 'Optional'} />
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
                name="amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{isSupplierType ? 'Amount Paid' : 'Amount'}</FormLabel>
                    <FormControl>
                      <RupiahInput value={field.value} onChange={field.onChange} disabled={isSupplierType && allocations.size > 0} />
                    </FormControl>
                    {isSupplierType && (
                      <FormDescription>
                        {allocations.size > 0
                          ? 'Calculated automatically from the bills checked below.'
                          : 'Type an amount to record an unapplied payment, or check a bill below to allocate directly.'}
                      </FormDescription>
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

          {isSupplierType && supplierId && (
            <Card>
              <CardHeader>
                <CardTitle>Outstanding Payables</CardTitle>
              </CardHeader>
              <CardContent>
                <OutstandingPayablesTable
                  payables={outstandingPayables}
                  isLoading={outstandingQuery.isLoading}
                  allocations={allocations}
                  onToggle={togglePayable}
                  onAllocationChange={updateAllocation}
                />
              </CardContent>
            </Card>
          )}

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && paymentQuery.data?.status === 'draft'
              ? isSupplierType
                ? allocations.size > 0
                  ? 'Confirming marks the money as paid and allocates it to the bills checked above.'
                  : 'Saving records the payment. Confirming marks the money as paid — allocate it to a bill afterward.'
                : 'Saving records the payment. Confirming posts it to the ledger — this cannot be undone.'
              : 'Saving records the payment as a draft — nothing is posted until you confirm it.'}
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
