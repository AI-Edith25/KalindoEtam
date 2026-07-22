import { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Plus, Save, Send, Trash2 } from 'lucide-react'
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
import { formatCurrency } from '@/lib/utils'
import { fetchInvoice, fetchInvoices } from '../api/invoiceApi'
import { createDebitNote, fetchDebitNote, submitDebitNote, updateDebitNote } from '../api/debitNoteApi'
import { debitNoteFormSchema, emptyDebitNoteEditorValues, type DebitNoteEditorValues } from '../lib/debitNoteFormSchema'
import { DEBIT_NOTE_REASON_OPTIONS, lineModeForReason } from '../lib/debitNoteReasonLabels'
import type { DebitNoteFormValues, DebitNoteReason, Invoice } from '../types'

interface ItemLineState {
  qtyAdjusted: string
  amount: string
}

interface FreeLine {
  key: string
  description: string
  amount: string
}

let freeLineCounter = 0
const nextFreeLineKey = () => `free-${++freeLineCounter}`

export function DebitNoteEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()

  const [selectedInvoiceId, setSelectedInvoiceId] = useState<string | null>(searchParams.get('invoice_id'))
  const [itemLines, setItemLines] = useState<Record<string, ItemLineState>>({})
  const [freeLines, setFreeLines] = useState<FreeLine[]>([])

  const debitNoteQuery = useQuery({
    queryKey: ['debit-notes', id],
    queryFn: () => fetchDebitNote(id!),
    enabled: isEdit,
  })

  // No creditable-balance filter, unlike Credit Note's Invoice picker — a Debit Note has no
  // ceiling to be eligible against, any submitted Invoice qualifies. See docs/DEBIT_NOTE_DESIGN.md §6.
  const eligibleInvoicesQuery = useQuery({
    queryKey: ['invoices-eligible-for-debit-note'],
    queryFn: () => fetchInvoices({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit,
  })
  const eligibleInvoices = eligibleInvoicesQuery.data?.data ?? []

  const form = useForm<DebitNoteEditorValues>({
    resolver: zodResolver(debitNoteFormSchema),
    defaultValues: emptyDebitNoteEditorValues,
  })

  useEffect(() => {
    const debitNote = debitNoteQuery.data
    if (!debitNote) return

    if (debitNote.status !== 'draft') {
      toast.error('Only draft Debit Notes can be edited.')
      navigate(`/sales/debit-notes/${debitNote.id}`, { replace: true })
      return
    }

    setSelectedInvoiceId(debitNote.invoice_id)
    form.reset({
      debit_note_date: debitNote.debit_note_date,
      reason: debitNote.reason,
      tax_amount: String(debitNote.tax_amount),
      remarks: debitNote.remarks ?? '',
    })
    setItemLines(
      Object.fromEntries(
        debitNote.items
          .filter((line) => line.invoice_item_id)
          .map((line) => [line.invoice_item_id as string, { qtyAdjusted: String(line.qty_adjusted), amount: String(line.amount) }]),
      ),
    )
    setFreeLines(
      debitNote.items
        .filter((line) => !line.invoice_item_id)
        .map((line) => ({ key: nextFreeLineKey(), description: line.description, amount: String(line.amount) })),
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debitNoteQuery.data])

  const selectedInvoice = eligibleInvoices.find((invoice) => invoice.id === selectedInvoiceId) ?? null

  const editInvoiceQuery = useQuery({
    queryKey: ['invoice-for-debit-note-edit', debitNoteQuery.data?.invoice_id],
    queryFn: () => fetchInvoice(debitNoteQuery.data!.invoice_id),
    enabled: isEdit && !!debitNoteQuery.data,
  })
  const activeInvoice: Invoice | null = isEdit ? (editInvoiceQuery.data ?? null) : selectedInvoice

  const reason = form.watch('reason') as DebitNoteReason | ''
  const lineMode = reason ? lineModeForReason(reason) : 'item'

  const setItemLine = (invoiceItemId: string, patch: Partial<ItemLineState>) => {
    setItemLines((prev) => {
      const current = prev[invoiceItemId] ?? { qtyAdjusted: '0', amount: '0' }
      return { ...prev, [invoiceItemId]: { ...current, ...patch } }
    })
  }

  const addFreeLine = () => setFreeLines((prev) => [...prev, { key: nextFreeLineKey(), description: '', amount: '' }])
  const removeFreeLine = (key: string) => setFreeLines((prev) => prev.filter((line) => line.key !== key))
  const setFreeLine = (key: string, patch: Partial<FreeLine>) =>
    setFreeLines((prev) => prev.map((line) => (line.key === key ? { ...line, ...patch } : line)))

  const toPayload = (values: DebitNoteEditorValues): DebitNoteFormValues => {
    const mode = lineModeForReason(values.reason as DebitNoteReason)
    const items: DebitNoteFormValues['items'] =
      mode === 'item'
        ? Object.entries(itemLines)
            .filter(([, line]) => Number(line.amount) > 0)
            .map(([invoiceItemId, line]) => ({
              invoice_item_id: invoiceItemId,
              description: null,
              qty_adjusted: Number(line.qtyAdjusted) || 0,
              rate: null,
              amount: Number(line.amount) || 0,
            }))
        : mode === 'freestanding'
          ? freeLines
              .filter((line) => line.description.trim() !== '' && Number(line.amount) > 0)
              .map((line) => ({ invoice_item_id: null, description: line.description.trim(), qty_adjusted: 0, rate: null, amount: Number(line.amount) || 0 }))
          : []

    return {
      invoice_id: selectedInvoiceId ?? '',
      debit_note_date: values.debit_note_date,
      reason: values.reason as DebitNoteReason,
      tax_amount: values.tax_amount === '' ? null : Number(values.tax_amount),
      remarks: values.remarks || null,
      items,
    }
  }

  const saveMutation = useMutation({
    mutationFn: (values: DebitNoteEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateDebitNote(id!, payload) : createDebitNote(payload)
    },
    onSuccess: (debitNote) => {
      queryClient.invalidateQueries({ queryKey: ['debit-notes'] })
      toast.success(isEdit ? 'Debit Note updated.' : 'Debit Note saved as draft.')
      if (!isEdit) {
        navigate(`/sales/debit-notes/${debitNote.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitDebitNote(id!),
    onSuccess: (debitNote) => {
      queryClient.invalidateQueries({ queryKey: ['debit-notes'] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Debit Note submitted — Accounts Receivable updated.')
      navigate(`/sales/debit-notes/${debitNote.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const subtotal =
    lineMode === 'item'
      ? Object.values(itemLines).reduce((sum, line) => sum + (Number(line.amount) || 0), 0)
      : lineMode === 'freestanding'
        ? freeLines.reduce((sum, line) => sum + (Number(line.amount) || 0), 0)
        : 0
  const watchedTax = Number(form.watch('tax_amount') || 0)
  const totalAmount = subtotal + watchedTax

  if (isEdit && (debitNoteQuery.isLoading || editInvoiceQuery.isLoading)) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): pick the Invoice this note debits.
  if (!isEdit && !selectedInvoiceId) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Debit Note" description="Increases a customer's receivable after a posted Invoice." />
        <Card>
          <CardHeader>
            <CardTitle>Select Invoice</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <Select value="" onValueChange={setSelectedInvoiceId} disabled={eligibleInvoicesQuery.isLoading}>
              <SelectTrigger className="w-full sm:w-96">
                <SelectValue
                  placeholder={eligibleInvoicesQuery.isLoading ? 'Loading…' : eligibleInvoices.length === 0 ? 'No submitted invoices' : 'Select invoice'}
                />
              </SelectTrigger>
              <SelectContent>
                {eligibleInvoices.map((row) => (
                  <SelectItem key={row.id} value={row.id}>
                    {row.document_number} — {row.customer?.customer_name} · Grand Total: {formatCurrency(row.grand_total)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">Any submitted invoice is eligible — a Debit Note has no remaining-balance ceiling.</p>
            <Button type="button" variant="outline" className="self-start" onClick={() => navigate('/sales/debit-notes')}>
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
        title={isEdit ? `Edit ${debitNoteQuery.data?.document_number ?? 'Debit Note'}` : 'New Debit Note'}
        description={`Debiting ${activeInvoice?.document_number ?? ''} — ${activeInvoice?.customer?.customer_name ?? ''}.`}
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Debit Note Details</CardTitle>
              <StatusBadge status={isEdit ? (debitNoteQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5 sm:col-span-2">
                <span className="text-xs text-muted-foreground">Invoice</span>
                <span className="text-sm font-medium">
                  {activeInvoice?.document_number} · Grand Total {formatCurrency(activeInvoice?.grand_total ?? 0)}
                </span>
              </div>
              <FormField
                control={form.control}
                name="debit_note_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Debit Note Date</FormLabel>
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
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select reason" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {DEBIT_NOTE_REASON_OPTIONS.map(([value, label]) => (
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
                    <FormLabel>Additional Tax</FormLabel>
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

          {lineMode === 'item' && (
            <Card>
              <CardHeader>
                <CardTitle>Line Adjustments</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Item</TableHead>
                        <TableHead className="text-right">Original Qty</TableHead>
                        <TableHead className="text-right">Original Amount</TableHead>
                        <TableHead className="w-32">Qty Adjusted</TableHead>
                        <TableHead className="w-36">Amount</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {invoiceItems.map((line) => {
                        const existing = itemLines[line.id]

                        return (
                          <TableRow key={line.id}>
                            <TableCell>
                              <div className="flex flex-col">
                                <span className="font-medium">{line.item_name}</span>
                                <span className="text-xs text-muted-foreground">{line.item_code}</span>
                              </div>
                            </TableCell>
                            <TableCell className="text-right">{line.qty}</TableCell>
                            <TableCell className="text-right">{formatCurrency(line.amount)}</TableCell>
                            <TableCell>
                              <Input
                                type="number"
                                min={0}
                                step="1"
                                placeholder="0"
                                value={existing?.qtyAdjusted ?? ''}
                                onChange={(event) => setItemLine(line.id, { qtyAdjusted: event.target.value })}
                              />
                            </TableCell>
                            <TableCell>
                              <Input
                                type="number"
                                min={0}
                                step="0.01"
                                placeholder="0"
                                value={existing?.amount ?? ''}
                                onChange={(event) => setItemLine(line.id, { amount: event.target.value })}
                              />
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          )}

          {lineMode === 'freestanding' && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Additional Charges</CardTitle>
                <Button type="button" variant="outline" size="sm" onClick={addFreeLine}>
                  <Plus className="size-4" />
                  Add Charge
                </Button>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Description</TableHead>
                        <TableHead className="w-40">Amount</TableHead>
                        <TableHead className="w-12" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {freeLines.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={3} className="text-center text-sm text-muted-foreground">
                            No charges added yet.
                          </TableCell>
                        </TableRow>
                      )}
                      {freeLines.map((line) => (
                        <TableRow key={line.key}>
                          <TableCell>
                            <Input
                              placeholder="e.g. Expedited handling fee"
                              value={line.description}
                              onChange={(event) => setFreeLine(line.key, { description: event.target.value })}
                            />
                          </TableCell>
                          <TableCell>
                            <Input
                              type="number"
                              min={0}
                              step="0.01"
                              placeholder="0"
                              value={line.amount}
                              onChange={(event) => setFreeLine(line.key, { amount: event.target.value })}
                            />
                          </TableCell>
                          <TableCell>
                            <Button type="button" variant="ghost" size="icon" onClick={() => removeFreeLine(line.key)}>
                              <Trash2 className="size-4" />
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          )}

          <Card>
            <CardContent className="flex flex-col items-end gap-1.5 py-4">
              <div className="flex w-full max-w-72 justify-between text-sm">
                <span className="text-muted-foreground">Subtotal</span>
                <span>{formatCurrency(subtotal)}</span>
              </div>
              <div className="flex w-full max-w-72 justify-between text-sm">
                <span className="text-muted-foreground">Additional Tax</span>
                <span>{formatCurrency(watchedTax)}</span>
              </div>
              <Separator className="w-full max-w-72" />
              <div className="flex w-full max-w-72 justify-between text-base font-semibold">
                <span>Total</span>
                <span>{formatCurrency(totalAmount)}</span>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/sales/debit-notes')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending || totalAmount <= 0}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && debitNoteQuery.data?.status === 'draft' && (
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
