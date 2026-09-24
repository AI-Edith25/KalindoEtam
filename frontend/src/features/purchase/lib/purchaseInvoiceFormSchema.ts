import { z } from 'zod'

/**
 * Header-only — Goods Receipt-sourced Purchase Invoice items are never entered by the user,
 * they are copied server-side from the selected Goods Receipts' items (see
 * PurchaseInvoiceService::createFromGoodsReceipts() on the backend). tax_amount is a manual
 * figure — Goods Receipt items carry no tax snapshot to derive it from (unlike Sales' per-line
 * tax). See directPurchaseInvoiceFormSchema below for the Direct/Non-Stock invoice, which does
 * have per-line entry.
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

/**
 * Direct/Non-Stock Purchase Invoice line — no Goods Receipt/PO/Item, posts straight to a
 * Chart-of-Accounts expense account. No qty_category (no Item to derive one from) — qty is
 * always free-form decimal. Mirrors directGoodsReceiptLineRowSchema in goodsReceiptFormSchema.ts.
 */
export const directPurchaseInvoiceLineRowSchema = z.object({
  chart_of_account_id: z.string().min(1, 'Account is required'),
  description: z.string().min(1, 'Description is required'),
  uom: z.string().optional().or(z.literal('')),
  qty: z
    .string()
    .min(1, 'Qty is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) > 0, 'Must be greater than zero'),
  rate: z
    .string()
    .min(1, 'Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  tax_id: z.string().optional().or(z.literal('')),
})

export const directPurchaseInvoiceFormSchema = z.object({
  supplier_id: z.string().min(1, 'Supplier is required'),
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().optional().or(z.literal('')),
  reference_number: z.string().optional().or(z.literal('')),
  attention: z.string().optional().or(z.literal('')),
  department: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(directPurchaseInvoiceLineRowSchema).min(1, 'Add at least one line item.'),
})

export type DirectPurchaseInvoiceEditorValues = z.infer<typeof directPurchaseInvoiceFormSchema>
export type DirectPurchaseInvoiceLineRow = z.infer<typeof directPurchaseInvoiceLineRowSchema>

export const emptyDirectPurchaseInvoiceEditorValues: DirectPurchaseInvoiceEditorValues = {
  supplier_id: '',
  invoice_date: '',
  due_date: '',
  reference_number: '',
  attention: '',
  department: '',
  remarks: '',
  items: [],
}
