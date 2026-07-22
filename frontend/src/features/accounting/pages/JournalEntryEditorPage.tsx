import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save, Send } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, cn } from '@/lib/utils'
import { createJournalEntry, fetchJournalEntry, postJournalEntry, updateJournalEntry } from '../api/journalEntryApi'
import { JournalEntryLineItemTable } from '../components/JournalEntryLineItemTable'
import { emptyJournalEntryEditorValues, journalEntryFormSchema, type JournalEntryEditorValues } from '../lib/journalEntryFormSchema'
import type { JournalEntryFormValues } from '../types'

export function JournalEntryEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const entryQuery = useQuery({
    queryKey: ['journal-entries', id],
    queryFn: () => fetchJournalEntry(id!),
    enabled: isEdit,
  })

  const form = useForm<JournalEntryEditorValues>({
    resolver: zodResolver(journalEntryFormSchema),
    defaultValues: emptyJournalEntryEditorValues,
  })

  useEffect(() => {
    const entry = entryQuery.data
    if (!entry) return

    if (entry.status !== 'draft') {
      toast.error('Only draft Journal Entries can be edited.')
      navigate(`/accounting/journal-entries/${entry.id}`, { replace: true })
      return
    }

    form.reset({
      posting_date: entry.posting_date,
      description: entry.description ?? '',
      lines: entry.lines.map((line) => ({
        chart_of_account_id: line.chart_of_account_id,
        debit: Number(line.debit) > 0 ? String(line.debit) : '',
        credit: Number(line.credit) > 0 ? String(line.credit) : '',
        description: line.description ?? '',
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entryQuery.data])

  const toPayload = (values: JournalEntryEditorValues): JournalEntryFormValues => ({
    posting_date: values.posting_date,
    description: values.description || null,
    lines: values.lines.map((line) => ({
      chart_of_account_id: line.chart_of_account_id,
      debit: Number(line.debit || 0),
      credit: Number(line.credit || 0),
      description: line.description || null,
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: JournalEntryEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateJournalEntry(id!, payload) : createJournalEntry(payload)
    },
    onSuccess: (entry) => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
      toast.success(isEdit ? 'Journal Entry updated.' : 'Journal Entry saved as draft.')
      if (!isEdit) {
        navigate(`/accounting/journal-entries/${entry.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const postMutation = useMutation({
    mutationFn: () => postJournalEntry(id!),
    onSuccess: (entry) => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
      toast.success('Journal Entry posted.')
      navigate(`/accounting/journal-entries/${entry.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  const watchedLines = useWatch({ control: form.control, name: 'lines' })
  const totalDebit = (watchedLines ?? []).reduce((sum, line) => sum + Number(line?.debit || 0), 0)
  const totalCredit = (watchedLines ?? []).reduce((sum, line) => sum + Number(line?.credit || 0), 0)
  const isBalanced = totalDebit > 0 && totalDebit === totalCredit

  if (isEdit && entryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${entryQuery.data?.document_number ?? 'Journal Entry'}` : 'New Journal Entry'}
        description="Journal Posting — record a manual, balanced double-entry transaction."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Entry Details</CardTitle>
              <StatusBadge status={isEdit ? (entryQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="posting_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Posting Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div />
              <FormField
                control={form.control}
                name="description"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Description</FormLabel>
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
              <CardTitle>Lines</CardTitle>
            </CardHeader>
            <CardContent>
              <JournalEntryLineItemTable form={form} />
              {form.formState.errors.lines?.root && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.lines.root.message}</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col items-end gap-1.5 py-4">
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">Total Debit</span>
                <span className={cn(!isBalanced && 'text-destructive font-medium')}>{formatCurrency(totalDebit)}</span>
              </div>
              <Separator className="w-full max-w-64" />
              <div className="flex w-full max-w-64 justify-between text-base font-semibold">
                <span>Total Credit</span>
                <span className={cn(!isBalanced && 'text-destructive')}>{formatCurrency(totalCredit)}</span>
              </div>
              {!isBalanced && <p className="mt-1 text-sm text-destructive">Debit and credit totals must match before this entry can be saved.</p>}
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/accounting/journal-entries')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending || !isBalanced}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && entryQuery.data?.status === 'draft' && (
              <Button type="button" onClick={() => postMutation.mutate()} disabled={postMutation.isPending || !isBalanced}>
                {postMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Post
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
