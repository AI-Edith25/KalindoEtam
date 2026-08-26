import { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchPurchaseInvoice, fetchPurchaseInvoices } from '../api/purchaseInvoiceApi'
import { createPurchaseReturn, fetchPurchaseReturn, submitPurchaseReturn, updatePurchaseReturn } from '../api/purchaseReturnApi'
import { purchaseReturnFormSchema, emptyPurchaseReturnEditorValues, type PurchaseReturnEditorValues } from '../lib/purchaseReturnFormSchema'
import { PURCHASE_RETURN_REASON_OPTIONS, reasonAllowsQuantity } from '../lib/purchaseReturnReasonLabels'
import type { PurchaseInvoice, PurchaseReturnFormValues, PurchaseReturnReason } from '../types'

interface LineState {
  qtyReturned: string
  amount: string
}

export function PurchaseReturnEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()

  const [selectedInvoiceId, setSelectedInvoiceId] = useState<string | null>(searchParams.get('purchase_invoice_id'))
  const [lines, setLines] = useState<Record<string, LineState>>({})

  const purchaseReturnQuery = useQuery({
    queryKey: ['purchase-returns', id],
    queryFn: () => fetchPurchaseReturn(id!),
    enabled: isEdit,
  })

  // Eligible = submitted with a remaining returnable balance. Fetched only in create mode.
  const eligibleInvoicesQuery = useQuery({
    queryKey: ['purchase-invoices-eligible-for-return'],
    queryFn: () => fetchPurchaseInvoices({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit,
  })
  const eligibleInvoices = (eligibleInvoicesQuery.data?.data ?? []).filter((invoice) => Number(invoice.returnable_amount) > 0)

  const form = useForm<PurchaseReturnEditorValues>({
    resolver: zodResolver(purchaseReturnFormSchema),
    defaultValues: emptyPurchaseReturnEditorValues,
  })

  useEffect(() => {
    const purchaseReturn = purchaseReturnQuery.data
    if (!purchaseReturn) return

    if (purchaseReturn.status !== 'draft') {
      toast.error('Only draft Purchase Returns can be edited.')
      navigate(`/purchase/returns/${purchaseReturn.id}`, { replace: true })
      return
    }

    setSelectedInvoiceId(purchaseReturn.purchase_invoice_id)
    form.reset({
      return_date: purchaseReturn.return_date,
      reason: purchaseReturn.reason,
      tax_amount: String(purchaseReturn.tax_amount),
      remarks: purchaseReturn.remarks ?? '',
    })
    setLines(
      Object.fromEntries(
        purchaseReturn.items.map((line) => [line.purchase_invoice_item_id, { qtyReturned: String(line.qty_returned), amount: String(line.amount) }]),
      ),
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [purchaseReturnQuery.data])

  const selectedInvoice = eligibleInvoices.find((invoice) => invoice.id === selectedInvoiceId) ?? null

  // In edit mode the Invoice is loaded separately (by id, not filtered to "eligible") so
  // remaining returnable figures per line correctly exclude this draft's own not-yet-submitted
  // amounts.
  const editInvoiceQuery = useQuery({
    queryKey: ['purchase-invoice-for-return-edit', purchaseReturnQuery.data?.purchase_invoice_id],
    queryFn: () => fetchPurchaseInvoice(purchaseReturnQuery.data!.purchase_invoice_id),
    enabled: isEdit && !!purchaseReturnQuery.data,
  })
  const activeInvoice: PurchaseInvoice | null = isEdit ? (editInvoiceQuery.data ?? null) : selectedInvoice

  const reason = form.watch('reason') as PurchaseReturnReason | ''

  const setLine = (invoiceItemId: string, patch: Partial<LineState>) => {
    setLines((prev) => {
      const current = prev[invoiceItemId] ?? { qtyReturned: '0', amount: '0' }
      return { ...prev, [invoiceItemId]: { ...current, ...patch } }
    })
  }

  const applyReasonDefaults = (nextReason: PurchaseReturnReason) => {
    if (!activeInvoice) return

    setLines((prev) => {
      const next: Record<string, LineState> = { ...prev }

      for (const line of activeInvoice.items) {
        const existing = prev[line.id]

        if (!reasonAllowsQuantity(nextReason)) {
          next[line.id] = { qtyReturned: '0', amount: existing?.amount ?? '0' }
        } else if (!existing) {
          next[line.id] = { qtyReturned: '0', amount: '0' }
        }
      }

      return next
    })
  }

  const toPayload = (values: PurchaseReturnEditorValues): PurchaseReturnFormValues => ({
    purchase_invoice_id: selectedInvoiceId ?? '',
    return_date: values.return_date,
    reason: values.reason as PurchaseReturnReason,
    tax_amount: values.tax_amount === '' ? null : Number(values.tax_amount),
    remarks: values.remarks || null,
    items: Object.entries(lines)
      .filter(([, line]) => Number(line.qtyReturned) > 0 || Number(line.amount) > 0)
      .map(([invoiceItemId, line]) => ({
        purchase_invoice_item_id: invoiceItemId,
        qty_returned: Number(line.qtyReturned) || 0,
        amount: Number(line.amount) || 0,
      })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: PurchaseReturnEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updatePurchaseReturn(id!, payload) : createPurchaseReturn(payload)
    },
    onSuccess: (purchaseReturn) => {
      queryClient.invalidateQueries({ queryKey: ['purchase-returns'] })
      toast.success(isEdit ? 'Purchase Return updated.' : 'Purchase Return saved as draft.')
      if (!isEdit) {
        navigate(`/purchase/returns/${purchaseReturn.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseReturn(id!),
    onSuccess: (purchaseReturn) => {
      queryClient.invalidateQueries({ queryKey: ['purchase-returns'] })
      queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Purchase Return submitted — Accounts Payable updated.')
      navigate(`/purchase/returns/${purchaseReturn.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const subtotal = Object.values(lines).reduce((sum, line) => sum + (Number(line.amount) || 0), 0)
  const watchedTax = Number(form.watch('tax_amount') || 0)
  const totalAmount = subtotal + watchedTax
  const remainingBalance = activeInvoice ? Number(activeInvoice.returnable_amount) : 0
  const exceedsBalance = totalAmount > remainingBalance

  if (isEdit && (purchaseReturnQuery.isLoading || editInvoiceQuery.isLoading)) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): pick the Purchase Invoice this return corrects.
  if (!isEdit && !selectedInvoiceId) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="New Return"
          description="Corrects a posted Purchase Invoice — the only accounting-correction path once an Invoice is submitted."
        />
        <Card>
          <CardHeader>
            <CardTitle>Select Purchase Invoice</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <Select value="" onValueChange={setSelectedInvoiceId} disabled={eligibleInvoicesQuery.isLoading}>
              <SelectTrigger className="w-full sm:w-96">
                <SelectValue
                  placeholder={
                    eligibleInvoicesQuery.isLoading
                      ? 'Loading…'
                      : eligibleInvoices.length === 0
                        ? 'No invoices with a returnable balance'
                        : 'Select purchase invoice'
                  }
                />
              </SelectTrigger>
              <SelectContent>
                {eligibleInvoices.map((row) => (
                  <SelectItem key={row.id} value={row.id}>
                    {row.document_number} — {row.supplier?.supplier_name} · Returnable: {formatCurrency(row.returnable_amount)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">Only submitted invoices with a remaining returnable balance are shown.</p>
            <Button type="button" variant="outline" className="self-start" onClick={() => navigate('/purchase/returns')}>
              Cancel
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const invoiceItems = activeInvoice?.items ?? []

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${purchaseReturnQuery.data?.document_number ?? 'Return'}` : 'New Return'}
        description={`Returning against ${activeInvoice?.document_number ?? ''} — ${activeInvoice?.supplier?.supplier_name ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Return Details</CardTitle>
              <StatusBadge status={isEdit ? (purchaseReturnQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Purchase Invoice</span>
                <span className="text-sm font-medium">
                  {activeInvoice?.document_number} · Grand Total {formatCurrency(activeInvoice?.grand_total ?? 0)} · Remaining returnable{' '}
                  {formatCurrency(remainingBalance)}
                </span>
              </div>
              <FormField
                control={form.control}
                name="return_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Return Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reason"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reason</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={(next) => {
                        field.onChange(next)
                        applyReasonDefaults(next as PurchaseReturnReason)
                      }}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select reason" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {PURCHASE_RETURN_REASON_OPTIONS.map(([value, label]) => (
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
                name="tax_amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tax Reversed</FormLabel>
                    <FormControl>
                      <Input type="number" min="0" placeholder="0" {...field} />
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
              <CardTitle>Line Selection</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto rounded-md border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Item</TableHead>
                      <TableHead className="text-right">Remaining Qty</TableHead>
                      <TableHead className="text-right">Remaining Amount</TableHead>
                      <TableHead className="w-32">Qty Returned</TableHead>
                      <TableHead className="w-36">Amount</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {invoiceItems.map((line) => {
                      const existing = lines[line.id]
                      const ownQty = existing ? Number(existing.qtyReturned) : 0
                      const ownAmount = existing ? Number(existing.amount) : 0
                      const capQty = Number(line.returnable_qty) + ownQty
                      const capAmount = Number(line.returnable_amount) + ownAmount
                      const quantityAllowed = reasonAllowsQuantity((reason || 'quantity_discrepancy') as PurchaseReturnReason)

                      return (
                        <TableRow key={line.id}>
                          <TableCell>
                            <div className="flex flex-col">
                              <span className="font-medium">{line.item_name}</span>
                              <span className="text-xs text-muted-foreground">{line.item_code}</span>
                            </div>
                          </TableCell>
                          <TableCell className="text-right">{formatNumber(line.returnable_qty)}</TableCell>
                          <TableCell className="text-right">{formatCurrency(line.returnable_amount)}</TableCell>
                          <TableCell>
                            <Input
                              type="number"
                              min={0}
                              max={capQty}
                              step="1"
                              placeholder="0"
                              disabled={!quantityAllowed}
                              value={existing?.qtyReturned ?? ''}
                              onChange={(event) => setLine(line.id, { qtyReturned: event.target.value })}
                            />
                          </TableCell>
                          <TableCell>
                            <Input
                              type="number"
                              min={0}
                              max={capAmount}
                              step="0.01"
                              placeholder="0"
                              value={existing?.amount ?? ''}
                              onChange={(event) => setLine(line.id, { amount: event.target.value })}
                            />
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">
                A Price Correction reason keeps quantity at 0 — no stock movement is posted for a pure billing correction. Every other reason moves stock
                out on submit.
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col items-end gap-1.5 py-4">
              <div className="flex w-full max-w-72 justify-between text-sm">
                <span className="text-muted-foreground">Subtotal</span>
                <span>{formatCurrency(subtotal)}</span>
              </div>
              <div className="flex w-full max-w-72 justify-between text-sm">
                <span className="text-muted-foreground">Tax Reversed</span>
                <span>{formatCurrency(watchedTax)}</span>
              </div>
              <Separator className="w-full max-w-72" />
              <div className="flex w-full max-w-72 justify-between text-base font-semibold">
                <span>Total</span>
                <span>{formatCurrency(totalAmount)}</span>
              </div>
              {exceedsBalance && (
                <p className="text-sm text-destructive">Exceeds the Invoice's remaining returnable balance ({formatCurrency(remainingBalance)}).</p>
              )}
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/purchase/returns')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending || totalAmount <= 0 || exceedsBalance}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && purchaseReturnQuery.data?.status === 'draft' && (
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
