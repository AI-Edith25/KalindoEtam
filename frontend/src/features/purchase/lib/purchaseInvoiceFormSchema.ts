import { z } from 'zod'

/**
 * Goods Receipt-sourced Purchase Invoice line — Item/Qty are locked copies of the source Goods
 * Receipt line (Qty always reflects what was actually received into stock), but Rate is free
 * to edit: the supplier's physical invoice amount can differ from what was recorded at receipt
 * time. Mirrors the legacy SkyBiz ERP's own free-edit PRICE field on its Invoice-from-GRN form —
 * no price-variance accounting, the edited Rate just becomes this Invoice's own Rate. `id` is
 * the source line's own id (a GoodsReceiptItem's id when creating, a PurchaseInvoiceItem's id
 * when editing) — used only as the row key, never sent to the backend (goods_receipt_item_id is).
 */
export const goodsReceiptSourcedLineRowSchema = z.object({
  id: z.string(),
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
 * tax_amount is a manual header figure — Goods Receipt items carry no tax snapshot to derive it
 * from (unlike Sales' per-line tax). See directPurchaseInvoiceFormSchema below for the
 * Direct/Non-Stock invoice, which has full per-line entry instead of just a Rate override.
 */
export const purchaseInvoiceFormSchema = z.object({
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  tax_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  reference_number: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(goodsReceiptSourcedLineRowSchema),
})

export type PurchaseInvoiceEditorValues = z.infer<typeof purchaseInvoiceFormSchema>
export type GoodsReceiptSourcedLineRow = z.infer<typeof goodsReceiptSourcedLineRowSchema>

export const emptyPurchaseInvoiceEditorValues: PurchaseInvoiceEditorValues = {
  invoice_date: '',
  due_date: '',
  tax_amount: '',
  reference_number: '',
  remarks: '',
  items: [],
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
