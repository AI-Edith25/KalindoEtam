import { z } from 'zod'

/**
 * Header-only — Invoice items are never entered by the user, they are
 * copied server-side from the source Delivery's items (see
 * InvoiceService::create() on the backend). discount_amount and
 * discount_percentage are kept as strings and converted to numbers only
 * when building the API payload, same string-then-convert pattern as
 * Sales Order/Delivery. Only the field matching discount_type is actually
 * sent — see InvoiceEditorPage's toPayload(). "Amount cannot exceed
 * subtotal" isn't checked here since subtotal isn't known to this static
 * schema; InvoiceEditorPage checks it against the live subtotal before
 * submit, and InvoiceService::resolveDiscount() is the authority either
 * way. tax_id replaces a manually-typed tax_amount — TaxService::calculate()
 * on the backend is the only place tax math happens (docs/TAX_ENGINE_DESIGN.md §6).
 */
export const invoiceFormSchema = z.object({
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  discount_type: z.enum(['amount', 'percentage']),
  discount_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  discount_percentage: z
    .string()
    .refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0 && Number(value) <= 100), 'Must be between 0 and 100'),
  tax_id: z.string(),
  remarks: z.string().optional().or(z.literal('')),
})

export type InvoiceEditorValues = z.infer<typeof invoiceFormSchema>

export const emptyInvoiceEditorValues: InvoiceEditorValues = {
  invoice_date: '',
  due_date: '',
  discount_type: 'amount',
  discount_amount: '',
  discount_percentage: '',
  tax_id: '',
  remarks: '',
}
