import { z } from 'zod'

/** True when `value` has no more than 2 decimal places — used only for the optional actual-weight field, never for qty. */
function hasAtMostTwoDecimals(value: number): boolean {
  return Math.abs(Math.round(value * 100) - value * 100) < 1e-6
}

/**
 * Truck-scale weight recorded alongside a line, purely for record-keeping
 * — never validated against qty, never affects rate/amount. Shared by both
 * the from-PO and direct-receipt row schemas.
 */
const weightFieldsSchema = {
  actual_weight: z
    .string()
    .optional()
    .or(z.literal(''))
    .refine(
      (value) => !value || (!Number.isNaN(Number(value)) && hasAtMostTwoDecimals(Number(value)) && Number(value) >= 0),
      'Must be zero or greater, at most 2 decimal places',
    ),
  weight_unit: z.enum(['kg', 'ton']).optional().or(z.literal('')),
  weighbridge_ref: z.string().optional().or(z.literal('')),
}

/**
 * One row of a from-PO receipt — item is user-selectable (Add/Remove Row),
 * restricted to the loaded Purchase Order's own lines (see
 * GoodsReceiptLineItemTable's item Select). The same PO line can be picked
 * on more than one row (different truck loads of the same item) — combined
 * `receiveNow` across every row sharing a `purchase_order_item_id` is
 * validated against that line's `remaining` at the array level (see
 * goodsReceiptFormSchema's superRefine below), not per row. `ordered`/
 * `alreadyReceived`/`remaining`/`allowOverReceipt`/`item_code`/`item_name`/
 * `rate` are a snapshot populated when the item is selected.
 */
export const goodsReceiptLineRowSchema = z
  .object({
    purchase_order_item_id: z.string().min(1, 'Item is required'),
    item_code: z.string(),
    item_name: z.string(),
    rate: z.number(),
    ordered: z.number(),
    alreadyReceived: z.number(),
    remaining: z.number(),
    allowOverReceipt: z.boolean(),
    receiveNow: z.string(),
    ...weightFieldsSchema,
  })
  .superRefine((line, ctx) => {
    const value = Number(line.receiveNow || 0)

    if (Number.isNaN(value) || !Number.isInteger(value) || value < 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Must be a whole number', path: ['receiveNow'] })
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
    .refine((value) => Number.isInteger(Number(value)) && Number(value) >= 1, 'Must be at least 1'),
  rate: z
    .string()
    .min(1, 'Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  ...weightFieldsSchema,
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

    // Same PO item can appear on multiple rows (different truck loads) — validate the combined
    // total, not each row alone, against that item's remaining outstanding qty.
    const groups = new Map<string, { total: number; remaining: number; allowOverReceipt: boolean; indexes: number[] }>()
    items.forEach((line, index) => {
      if (!line.purchase_order_item_id) return
      const value = Number(line.receiveNow || 0)
      const group = groups.get(line.purchase_order_item_id)
      if (group) {
        group.total += value
        group.indexes.push(index)
      } else {
        groups.set(line.purchase_order_item_id, {
          total: value,
          remaining: line.remaining,
          allowOverReceipt: line.allowOverReceipt,
          indexes: [index],
        })
      }
    })

    groups.forEach(({ total, remaining, allowOverReceipt, indexes }) => {
      if (total > remaining && !allowOverReceipt) {
        indexes.forEach((index) => {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            message: `Combined Receive Now for this item (${total}) exceeds remaining (${remaining})`,
            path: [index, 'receiveNow'],
          })
        })
      }
    })
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
