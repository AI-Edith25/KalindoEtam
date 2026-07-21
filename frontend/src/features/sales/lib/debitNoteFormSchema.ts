import { z } from 'zod'

/**
 * Header-only — line items are managed as separate local state in the
 * editor, same shape as CreditNoteEditorPage. No discount_amount field,
 * unlike Credit Note/Invoice — a Debit Note only ever increases the total,
 * so a subtractive "discount" field doesn't fit. See docs/DEBIT_NOTE_DESIGN.md §2.
 */
export const debitNoteFormSchema = z.object({
  debit_note_date: z.string().min(1, 'Debit Note date is required'),
  reason: z.string().min(1, 'Reason is required'),
  tax_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  remarks: z.string().optional().or(z.literal('')),
})

export type DebitNoteEditorValues = z.infer<typeof debitNoteFormSchema>

export const emptyDebitNoteEditorValues: DebitNoteEditorValues = {
  debit_note_date: '',
  reason: '',
  tax_amount: '',
  remarks: '',
}
