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
import { fetchBranches, fetchSuppliersLookup, fetchChartOfAccountsLookup } from '@/features/master/api/lookupsApi'
import { createPaymentEntry, fetchPaymentEntry, submitPaymentEntry, updatePaymentEntry, type PaymentEntryPayload } from '../api/paymentEntryApi'
import { OutstandingPayableSelect } from '../components/OutstandingPayableSelect'
import { paymentEntryFormSchema, type PaymentEntryEditorValues } from '../lib/paymentEntryFormSchema'
import type { AccountsPayable, PaymentEntryType } from '../types'

const emptyValues: PaymentEntryEditorValues = {
  payment_type: 'supplier',
  supplier_id: '',
  accounts_payable_id: '',
  outstandingAmount: 0,
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

  const [selectedPayable, setSelectedPayable] = useState<AccountsPayable | null>(null)

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
      payment_type: payment.payment_type,
      supplier_id: payment.supplier_id ?? '',
      accounts_payable_id: line?.accounts_payable_id ?? '',
      outstandingAmount: line ? Number(line.accounts_payable.outstanding_amount) : 0,
      expense_account_id: payment.expense_account_id ?? '',
      description: payment.description ?? '',
      amount: payment.payment_type === 'general_expense' ? String(payment.total_amount) : line ? String(line.paid_amount) : '',
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
              items: [{ accounts_payable_id: values.accounts_payable_id, paid_amount: Number(values.amount) }],
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
    mutationFn: () => submitPaymentEntry(id!),
    onSuccess: (payment) => {
      queryClient.invalidateQueries({ queryKey: ['payment-entries'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Payment confirmed.')
      navigate(`/finance/outgoing/${payment.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const supplierId = form.watch('supplier_id')
  const paymentType = form.watch('payment_type')
  const isSupplierType = paymentType === 'supplier'

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
                        form.setValue('accounts_payable_id', '')
                        form.setValue('outstandingAmount', 0)
                        form.setValue('expense_account_id', '')
                        form.setValue('description', '')
                        form.setValue('amount', '')
                        setSelectedPayable(null)
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
                <>
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
                </>
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
                    <FormLabel>Amount</FormLabel>
                    <FormControl>
                      <Input type="number" step="0.01" placeholder="0" {...field} />
                    </FormControl>
                    {isSupplierType && selectedPayable && (
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

          {isSupplierType && selectedPayable && (
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
              ? isSupplierType
                ? 'Saving records the payment. Confirming settles it against the payable — this cannot be undone.'
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
