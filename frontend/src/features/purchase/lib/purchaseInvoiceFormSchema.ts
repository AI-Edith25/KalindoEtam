import { z } from 'zod'

/**
 * Header-only — Purchase Invoice items are never entered by the user, they
 * are copied server-side from the selected Goods Receipts' items (see
 * PurchaseInvoiceService::create() on the backend). tax_amount is a manual
 * figure — Goods Receipt items carry no tax snapshot to derive it from
 * (unlike Sales' per-line tax), same posture as the plan's documented scope
 * trim.
 */
export const purchaseInvoiceFormSchema = z.object({
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  tax_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  reference_number: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
})

export type PurchaseInvoiceEditorValues = z.infer<typeof purchaseInvoiceFormSchema>

export const emptyPurchaseInvoiceEditorValues: PurchaseInvoiceEditorValues = {
  invoice_date: '',
  due_date: '',
  tax_amount: '',
  reference_number: '',
  remarks: '',
}
