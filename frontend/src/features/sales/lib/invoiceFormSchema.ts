import { z } from 'zod'

/**
 * Header-only — Invoice items are never entered by the user here (Goods: copied server-side
 * from the source Delivery's items; Transportation/Direct Goods: entered as freestanding/
 * item-backed lines, see InvoiceEditorPage's TransportLine/DirectGoodsLine). Discount and Tax
 * are per-line now (see DiscountInput/the Line Items table in InvoiceEditorPage) — this schema
 * carries no header discount_type/discount_amount/discount_percentage/tax_id at all anymore.
 */
export const invoiceFormSchema = z.object({
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  terms_of_payment_id: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  sales_person_id: z.string().optional().or(z.literal('')),
  reference_1: z.string().optional().or(z.literal('')),
  reference_2: z.string().optional().or(z.literal('')),
  // The printed/displayed Location — every flow gets this field, unlike warehouse_id (Direct
  // Goods only, drives stock consumption). See Invoice::locationWarehouse().
  location_warehouse_id: z.string().optional().or(z.literal('')),
})

export type InvoiceEditorValues = z.infer<typeof invoiceFormSchema>

export const emptyInvoiceEditorValues: InvoiceEditorValues = {
  invoice_date: '',
  due_date: '',
  terms_of_payment_id: '',
  remarks: '',
  sales_person_id: '',
  reference_1: '',
  reference_2: '',
  location_warehouse_id: '',
}
