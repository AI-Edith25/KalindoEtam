import { z } from 'zod'

/**
 * Goods Receipt-sourced Purchase Invoice line ("stock") — Item/Qty are locked copies of the
 * source Goods Receipt line (Qty always reflects what was actually received into stock), but
 * Rate is free to edit: the supplier's physical invoice amount can differ from what was recorded
 * at receipt time. No price-variance accounting, the edited Rate just becomes this Invoice's own
 * Rate. goods_receipt_item_id ties the row back to its source Goods Receipt item for the Rate
 * override payload.
 */
export const purchaseInvoiceStockLineRowSchema = z.object({
  kind: z.literal('stock'),
  goods_receipt_item_id: z.string(),
  item_code: z.string().nullable(),
  item_name: z.string(),
  uom: z.string().nullable(),
  qty: z.union([z.string(), z.number()]),
  qty_category: z.enum(['unit', 'weight']).optional(),
  rate: z
    .string()
    .min(1, 'Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
})

/**
 * Non-stock ("manual") Purchase Invoice line — no Item, no Goods Receipt. Posts straight to a
 * Chart-of-Accounts Expense or Liability account instead of an Item, types its own free-text
 * Description/UOM, and its own optional Tax.
 */
export const purchaseInvoiceNonStockLineRowSchema = z.object({
  kind: z.literal('non_stock'),
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

export const purchaseInvoiceLineRowSchema = z.discriminatedUnion('kind', [
  purchaseInvoiceStockLineRowSchema,
  purchaseInvoiceNonStockLineRowSchema,
])

/**
 * Unified Purchase Invoice form — one or more stock rows (from a selected Goods Receipt), one or
 * more non-stock rows (manually added), or both ("Mixed"). tax_amount is a manual header figure
 * covering only the stock portion — Goods Receipt items carry no tax snapshot to derive it from —
 * each non-stock row carries its own tax instead, summed server-side.
 */
export const purchaseInvoiceFormSchema = z.object({
  supplier_id: z.string().min(1, 'Supplier is required'),
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().optional().or(z.literal('')),
  tax_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  reference_number: z.string().optional().or(z.literal('')),
  attention: z.string().optional().or(z.literal('')),
  department: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(purchaseInvoiceLineRowSchema).min(1, 'Add at least one line item — select a Goods Receipt or add a non-stock line.'),
})

export type PurchaseInvoiceEditorValues = z.infer<typeof purchaseInvoiceFormSchema>
export type PurchaseInvoiceLineRow = z.infer<typeof purchaseInvoiceLineRowSchema>
export type PurchaseInvoiceStockLineRow = z.infer<typeof purchaseInvoiceStockLineRowSchema>
export type PurchaseInvoiceNonStockLineRow = z.infer<typeof purchaseInvoiceNonStockLineRowSchema>

export const emptyPurchaseInvoiceEditorValues: PurchaseInvoiceEditorValues = {
  supplier_id: '',
  invoice_date: '',
  due_date: '',
  tax_amount: '',
  reference_number: '',
  attention: '',
  department: '',
  remarks: '',
  items: [],
}
