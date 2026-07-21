import { z } from 'zod'

/**
 * One row per Sales Order line — never added or removed by the user
 * (Delivery can't have free item selection). `deliverNow` is the only
 * editable field per row; `ordered`/`alreadyDelivered`/`remaining` are a
 * read-only snapshot taken when the Sales Order was loaded, and
 * `availableStock` a read-only snapshot from the bulk stock-balance
 * lookup for the chosen warehouse. Two independent ceilings — remaining
 * order quantity, and physical stock on hand — either can bind, so each
 * gets its own message rather than one generic "too much" error.
 */
export const deliveryLineRowSchema = z
  .object({
    sales_order_item_id: z.string(),
    item_id: z.string(),
    item_code: z.string(),
    item_name: z.string(),
    rate: z.number(),
    ordered: z.number(),
    alreadyDelivered: z.number(),
    remaining: z.number(),
    availableStock: z.number(),
    deliverNow: z.string(),
  })
  .superRefine((line, ctx) => {
    const value = Number(line.deliverNow || 0)

    if (Number.isNaN(value) || !Number.isInteger(value) || value < 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Must be a whole number', path: ['deliverNow'] })
      return
    }

    if (value > line.remaining) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: `Cannot exceed remaining order quantity (${line.remaining})`,
        path: ['deliverNow'],
      })
      return
    }

    if (value > line.availableStock) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: `Cannot exceed available stock (${line.availableStock})`,
        path: ['deliverNow'],
      })
    }
  })

export const deliveryFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  delivery_date: z.string().min(1, 'Delivery date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(deliveryLineRowSchema).superRefine((items, ctx) => {
    const hasAny = items.some((line) => Number(line.deliverNow || 0) > 0)
    if (!hasAny) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Deliver at least one line item.' })
    }
  }),
})

export type DeliveryEditorValues = z.infer<typeof deliveryFormSchema>
export type DeliveryLineRow = z.infer<typeof deliveryLineRowSchema>
