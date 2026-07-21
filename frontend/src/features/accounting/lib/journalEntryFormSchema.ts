import { z } from 'zod'

/**
 * Debit/credit kept as strings and converted to numbers only when building
 * the API payload — same string-then-convert pattern as every other line
 * item table (see SalesOrderLineItemTable). Each line must have exactly
 * one side filled, matching JournalEntryService's own "single-sided line"
 * rule — checked here too so the error shows before the request even fires.
 */
export const journalEntryLineFormSchema = z
  .object({
    chart_of_account_id: z.string().min(1, 'Account is required'),
    debit: z.string().refine((value) => value === '' || !Number.isNaN(Number(value)), 'Must be a number'),
    credit: z.string().refine((value) => value === '' || !Number.isNaN(Number(value)), 'Must be a number'),
    description: z.string().optional().or(z.literal('')),
  })
  .refine(
    (line) => {
      const debit = Number(line.debit || 0)
      const credit = Number(line.credit || 0)
      return (debit > 0) !== (credit > 0)
    },
    { message: 'Enter either a debit or a credit amount, not both or neither.', path: ['debit'] },
  )

export const journalEntryFormSchema = z.object({
  posting_date: z.string().min(1, 'Posting date is required'),
  description: z.string().optional().or(z.literal('')),
  lines: z.array(journalEntryLineFormSchema).min(2, 'A Journal Entry needs at least two lines'),
})

export type JournalEntryEditorValues = z.infer<typeof journalEntryFormSchema>

export const emptyJournalEntryEditorValues: JournalEntryEditorValues = {
  posting_date: '',
  description: '',
  lines: [
    { chart_of_account_id: '', debit: '', credit: '', description: '' },
    { chart_of_account_id: '', debit: '', credit: '', description: '' },
  ],
}
