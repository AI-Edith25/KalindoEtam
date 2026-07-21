import { z } from 'zod'

/**
 * Header-only — line items are managed as separate local state in the
 * editor (keyed by invoice_item_id, capped against each line's
 * creditable_qty/creditable_amount), same shape as PaymentAllocationDrawer's
 * amounts state rather than a react-hook-form field array, since rows are
 * fixed by the selected Invoice's items, never freely added/removed.
 */
export const creditNoteFormSchema = z.object({
  credit_note_date: z.string().min(1, 'Credit Note date is required'),
  reason: z.string().min(1, 'Reason is required'),
  discount_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  tax_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  remarks: z.string().optional().or(z.literal('')),
})

export type CreditNoteEditorValues = z.infer<typeof creditNoteFormSchema>

export const emptyCreditNoteEditorValues: CreditNoteEditorValues = {
  credit_note_date: '',
  reason: '',
  discount_amount: '',
  tax_amount: '',
  remarks: '',
}
