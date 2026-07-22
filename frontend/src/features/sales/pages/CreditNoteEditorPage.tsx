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
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchInvoice, fetchInvoices } from '../api/invoiceApi'
import { createCreditNote, fetchCreditNote, submitCreditNote, updateCreditNote } from '../api/creditNoteApi'
import { creditNoteFormSchema, emptyCreditNoteEditorValues, type CreditNoteEditorValues } from '../lib/creditNoteFormSchema'
import { CREDIT_NOTE_REASON_OPTIONS, defaultRestockForReason, reasonAllowsQuantity } from '../lib/creditNoteReasonLabels'
import type { CreditNoteFormValues, CreditNoteReason, Invoice } from '../types'

interface LineState {
  qtyCredited: string
  amount: string
  restock: boolean
}

export function CreditNoteEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()

  const [selectedInvoiceId, setSelectedInvoiceId] = useState<string | null>(searchParams.get('invoice_id'))
  const [lines, setLines] = useState<Record<string, LineState>>({})

  const creditNoteQuery = useQuery({
    queryKey: ['credit-notes', id],
    queryFn: () => fetchCreditNote(id!),
    enabled: isEdit,
  })

  // Eligible = submitted with a remaining creditable balance. Fetched only in create mode, before an Invoice is picked.
  const eligibleInvoicesQuery = useQuery({
    queryKey: ['invoices-eligible-for-credit-note'],
    queryFn: () => fetchInvoices({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit,
  })
  const eligibleInvoices = (eligibleInvoicesQuery.data?.data ?? []).filter((invoice) => Number(invoice.creditable_amount) > 0)

  const form = useForm<CreditNoteEditorValues>({
    resolver: zodResolver(creditNoteFormSchema),
    defaultValues: emptyCreditNoteEditorValues,
  })

  useEffect(() => {
    const creditNote = creditNoteQuery.data
    if (!creditNote) return

    if (creditNote.status !== 'draft') {
      toast.error('Only draft Credit Notes can be edited.')
      navigate(`/sales/credit-notes/${creditNote.id}`, { replace: true })
      return
    }

    setSelectedInvoiceId(creditNote.invoice_id)
    form.reset({
      credit_note_date: creditNote.credit_note_date,
      reason: creditNote.reason,
      discount_amount: String(creditNote.discount_amount),
      tax_amount: String(creditNote.tax_amount),
      remarks: creditNote.remarks ?? '',
    })
    setLines(
      Object.fromEntries(
        creditNote.items.map((line) => [
          line.invoice_item_id,
          { qtyCredited: String(line.qty_credited), amount: String(line.amount), restock: line.restock },
        ]),
      ),
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [creditNoteQuery.data])

  const selectedInvoice = eligibleInvoices.find((invoice) => invoice.id === selectedInvoiceId) ?? null

  // In edit mode the Invoice is loaded separately (by id, not filtered to "eligible") so
  // remaining creditable figures per line correctly exclude this draft's own not-yet-submitted
  // amounts, and so the invoice still resolves even though its full balance may already be used.
  const editInvoiceQuery = useQuery({
    queryKey: ['invoice-for-credit-note-edit', creditNoteQuery.data?.invoice_id],
    queryFn: () => fetchInvoice(creditNoteQuery.data!.invoice_id),
    enabled: isEdit && !!creditNoteQuery.data,
  })
  const activeInvoice: Invoice | null = isEdit ? (editInvoiceQuery.data ?? null) : selectedInvoice

  const reason = form.watch('reason') as CreditNoteReason | ''

  const setLine = (invoiceItemId: string, patch: Partial<LineState>) => {
    setLines((prev) => {
      const current = prev[invoiceItemId] ?? { qtyCredited: '0', amount: '0', restock: false }
      return { ...prev, [invoiceItemId]: { ...current, ...patch } }
    })
  }

  const applyReasonDefaults = (nextReason: CreditNoteReason) => {
    if (!activeInvoice) return

    setLines((prev) => {
      const next: Record<string, LineState> = { ...prev }

      for (const line of activeInvoice.items) {
        const existing = prev[line.id]
        const ownQty = existing ? Number(existing.qtyCredited) : 0
        const cap = { qty: Number(line.creditable_qty) + ownQty, amount: Number(line.creditable_amount) + Number(existing?.amount ?? 0) }

        if (nextReason === 'full_credit') {
          next[line.id] = { qtyCredited: String(cap.qty), amount: String(cap.amount), restock: true }
        } else if (!reasonAllowsQuantity(nextReason)) {
          next[line.id] = { qtyCredited: '0', amount: existing?.amount ?? '0', restock: false }
        } else {
          next[line.id] = { qtyCredited: existing?.qtyCredited ?? '0', amount: existing?.amount ?? '0', restock: defaultRestockForReason(nextReason) }
        }
      }

      return next
    })
  }

  const toPayload = (values: CreditNoteEditorValues): CreditNoteFormValues => ({
    invoice_id: selectedInvoiceId ?? '',
    credit_note_date: values.credit_note_date,
    reason: values.reason as CreditNoteReason,
    discount_amount: values.discount_amount === '' ? null : Number(values.discount_amount),
    tax_amount: values.tax_amount === '' ? null : Number(values.tax_amount),
    remarks: values.remarks || null,
    items: Object.entries(lines)
      .filter(([, line]) => Number(line.qtyCredited) > 0 || Number(line.amount) > 0)
      .map(([invoiceItemId, line]) => ({
        invoice_item_id: invoiceItemId,
        qty_credited: Number(line.qtyCredited) || 0,
        amount: Number(line.amount) || 0,
        restock: line.restock,
      })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: CreditNoteEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateCreditNote(id!, payload) : createCreditNote(payload)
    },
    onSuccess: (creditNote) => {
      queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
      toast.success(isEdit ? 'Credit Note updated.' : 'Credit Note saved as draft.')
      if (!isEdit) {
        navigate(`/sales/credit-notes/${creditNote.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitCreditNote(id!),
    onSuccess: (creditNote) => {
      queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Credit Note submitted — Accounts Receivable updated.')
      navigate(`/sales/credit-notes/${creditNote.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const subtotal = Object.values(lines).reduce((sum, line) => sum + (Number(line.amount) || 0), 0)
  const watchedDiscount = Number(form.watch('discount_amount') || 0)
  const watchedTax = Number(form.watch('tax_amount') || 0)
  const totalAmount = subtotal - watchedDiscount + watchedTax
  const remainingBalance = activeInvoice ? Number(activeInvoice.creditable_amount) : 0
  const exceedsBalance = totalAmount > remainingBalance

  if (isEdit && (creditNoteQuery.isLoading || editInvoiceQuery.isLoading)) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): pick the Invoice this note corrects.
  if (!isEdit && !selectedInvoiceId) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Credit Note" description="Corrects a posted Invoice — the only accounting-correction path once an Invoice is submitted." />
        <Card>
          <CardHeader>
            <CardTitle>Select Invoice</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <Select value="" onValueChange={setSelectedInvoiceId} disabled={eligibleInvoicesQuery.isLoading}>
              <SelectTrigger className="w-full sm:w-96">
                <SelectValue
                  placeholder={
                    eligibleInvoicesQuery.isLoading
                      ? 'Loading…'
                      : eligibleInvoices.length === 0
                        ? 'No invoices with a creditable balance'
                        : 'Select invoice'
                  }
                />
              </SelectTrigger>
              <SelectContent>
                {eligibleInvoices.map((row) => (
                  <SelectItem key={row.id} value={row.id}>
                    {row.document_number} — {row.customer?.customer_name} · Creditable: {formatCurrency(row.creditable_amount)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">Only submitted invoices with a remaining creditable balance are shown.</p>
            <Button type="button" variant="outline" className="self-start" onClick={() => navigate('/sales/credit-notes')}>
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
        title={isEdit ? `Edit ${creditNoteQuery.data?.document_number ?? 'Credit Note'}` : 'New Credit Note'}
        description={`Crediting ${activeInvoice?.document_number ?? ''} — ${activeInvoice?.customer?.customer_name ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Credit Note Details</CardTitle>
              <StatusBadge status={isEdit ? (creditNoteQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Invoice</span>
                <span className="text-sm font-medium">
                  {activeInvoice?.document_number} · Grand Total {formatCurrency(activeInvoice?.grand_total ?? 0)} · Remaining creditable{' '}
                  {formatCurrency(remainingBalance)}
                </span>
              </div>
              <FormField
                control={form.control}
                name="credit_note_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Credit Note Date</FormLabel>
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
                        applyReasonDefaults(next as CreditNoteReason)
                      }}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select reason" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {CREDIT_NOTE_REASON_OPTIONS.map(([value, label]) => (
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
                name="discount_amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Discount Reversed</FormLabel>
                    <FormControl>
                      <Input type="number" min="0" placeholder="0" {...field} />
                    </FormControl>
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
                      <TableHead className="w-32">Qty Credited</TableHead>
                      <TableHead className="w-36">Amount</TableHead>
                      <TableHead className="w-40">Restock</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {invoiceItems.map((line) => {
                      const existing = lines[line.id]
                      const ownQty = existing ? Number(existing.qtyCredited) : 0
                      const ownAmount = existing ? Number(existing.amount) : 0
                      const capQty = Number(line.creditable_qty) + ownQty
                      const capAmount = Number(line.creditable_amount) + ownAmount
                      const quantityAllowed = reasonAllowsQuantity((reason || 'partial_credit') as CreditNoteReason)

                      return (
                        <TableRow key={line.id}>
                          <TableCell>
                            <div className="flex flex-col">
                              <span className="font-medium">{line.item_name}</span>
                              <span className="text-xs text-muted-foreground">{line.item_code}</span>
                            </div>
                          </TableCell>
                          <TableCell className="text-right">{formatNumber(line.creditable_qty)}</TableCell>
                          <TableCell className="text-right">{formatCurrency(line.creditable_amount)}</TableCell>
                          <TableCell>
                            <Input
                              type="number"
                              min={0}
                              max={capQty}
                              step="1"
                              placeholder="0"
                              disabled={!quantityAllowed}
                              value={existing?.qtyCredited ?? ''}
                              onChange={(event) => setLine(line.id, { qtyCredited: event.target.value })}
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
                          <TableCell>
                            <div className="flex items-center gap-2">
                              <Switch
                                checked={existing?.restock ?? false}
                                disabled={!quantityAllowed || ownQty === 0}
                                onCheckedChange={(checked) => setLine(line.id, { restock: checked })}
                              />
                              {existing?.restock && <span className="text-xs text-muted-foreground">Pending Inventory Return Module</span>}
                            </div>
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col items-end gap-1.5 py-4">
              <div className="flex w-full max-w-72 justify-between text-sm">
                <span className="text-muted-foreground">Subtotal</span>
                <span>{formatCurrency(subtotal)}</span>
              </div>
              <div className="flex w-full max-w-72 justify-between text-sm">
                <span className="text-muted-foreground">Discount Reversed</span>
                <span>-{formatCurrency(watchedDiscount)}</span>
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
                <p className="text-sm text-destructive">Exceeds the Invoice's remaining creditable balance ({formatCurrency(remainingBalance)}).</p>
              )}
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/sales/credit-notes')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending || totalAmount <= 0 || exceedsBalance}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && creditNoteQuery.data?.status === 'draft' && (
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
