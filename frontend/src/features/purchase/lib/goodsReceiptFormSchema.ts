import { z } from 'zod'

/**
 * One row per Purchase Order line — never added or removed by the user
 * (Goods Receipt can't have free item selection). `receiveNow` is the
 * only editable field per row; `ordered`/`alreadyReceived`/`remaining`
 * are a read-only snapshot taken when the Purchase Order was loaded.
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
    receiveNow: z.string(),
  })
  .superRefine((line, ctx) => {
    const value = Number(line.receiveNow || 0)

    if (Number.isNaN(value) || !Number.isInteger(value) || value < 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Must be a whole number', path: ['receiveNow'] })
      return
    }

    if (value > line.remaining) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: `Cannot exceed remaining (${line.remaining})`,
        path: ['receiveNow'],
      })
    }
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

export type GoodsReceiptEditorValues = z.infer<typeof goodsReceiptFormSchema>
export type GoodsReceiptLineRow = z.infer<typeof goodsReceiptLineRowSchema>
