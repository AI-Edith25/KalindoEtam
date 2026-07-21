import { z } from 'zod'

/**
 * Header-only — Invoice items are never entered by the user, they are
 * copied server-side from the source Delivery's items (see
 * InvoiceService::create() on the backend). discount_amount is kept as a
 * string and converted to a number only when building the API payload,
 * same string-then-convert pattern as Sales Order/Delivery. tax_id
 * replaces a manually-typed tax_amount — TaxService::calculate() on the
 * backend is the only place tax math happens (docs/TAX_ENGINE_DESIGN.md §6).
 */
export const invoiceFormSchema = z.object({
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  discount_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  tax_id: z.string(),
  remarks: z.string().optional().or(z.literal('')),
})

export type InvoiceEditorValues = z.infer<typeof invoiceFormSchema>

export const emptyInvoiceEditorValues: InvoiceEditorValues = {
  invoice_date: '',
  due_date: '',
  discount_amount: '',
  tax_id: '',
  remarks: '',
}
