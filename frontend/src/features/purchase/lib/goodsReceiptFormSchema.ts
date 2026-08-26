import { z } from 'zod'

/** True when `value` has no more than 2 decimal places (bulk items are received by truck-scale weight, e.g. 50.65). */
function hasAtMostTwoDecimals(value: number): boolean {
  return Math.abs(Math.round(value * 100) - value * 100) < 1e-6
}

/**
 * One row per Purchase Order line — never added or removed by the user
 * (Goods Receipt can't have free item selection). `receiveNow` is the
 * only editable field per row; `ordered`/`alreadyReceived`/`remaining`
 * are a read-only snapshot taken when the Purchase Order was loaded.
 * `allowOverReceipt` mirrors the line's Item.allow_over_receipt flag —
 * only then can `receiveNow` exceed `remaining`.
 */
export const goodsReceiptLineRowSchema = z
  .object({
    purchase_order_item_id: z.string(),
    item_code: z.string(),
    item_name: z.string(),
    rate: z.number(),
    ordered: z.number(),
    alreadyReceived: z.number(),
    remaining: z.number(),
    allowOverReceipt: z.boolean(),
    receiveNow: z.string(),
  })
  .superRefine((line, ctx) => {
    const value = Number(line.receiveNow || 0)

    if (Number.isNaN(value) || !hasAtMostTwoDecimals(value) || value < 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Must have at most 2 decimal places', path: ['receiveNow'] })
      return
    }

    if (value > line.remaining && !line.allowOverReceipt) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: `Cannot exceed remaining (${line.remaining})`,
        path: ['receiveNow'],
      })
    }
  })

/**
 * One row of a standalone/direct receipt (no source Purchase Order) —
 * user picks the Item and types qty/rate directly, no PO line to snapshot
 * or cap against. Mirrors PurchaseOrderLineItemTable's row shape.
 */
export const directGoodsReceiptLineRowSchema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  item_code: z.string().optional().or(z.literal('')),
  item_name: z.string().optional().or(z.literal('')),
  qty: z
    .string()
    .min(1, 'Qty is required')
    .refine((value) => {
      const num = Number(value)
      return !Number.isNaN(num) && hasAtMostTwoDecimals(num) && num > 0
    }, 'Must be greater than 0, at most 2 decimal places'),
  rate: z
    .string()
    .min(1, 'Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
})

export const goodsReceiptFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  receipt_date: z.string().min(1, 'Receipt date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(goodsReceiptLineRowSchema).superRefine((items, ctx) => {
    const hasAny = items.some((line) => Number(line.receiveNow || 0) > 0)
    if (!hasAny) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Receive at least one line item.' })
    }
  }),
})

export const directGoodsReceiptFormSchema = z.object({
  supplier_id: z.string().min(1, 'Supplier is required'),
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  receipt_date: z.string().min(1, 'Receipt date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(directGoodsReceiptLineRowSchema).min(1, 'Add at least one line item.'),
})

export type GoodsReceiptEditorValues = z.infer<typeof goodsReceiptFormSchema>
export type GoodsReceiptLineRow = z.infer<typeof goodsReceiptLineRowSchema>
export type DirectGoodsReceiptEditorValues = z.infer<typeof directGoodsReceiptFormSchema>
export type DirectGoodsReceiptLineRow = z.infer<typeof directGoodsReceiptLineRowSchema>
