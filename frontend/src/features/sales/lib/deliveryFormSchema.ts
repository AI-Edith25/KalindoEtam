import { z } from 'zod'
import { isValidQtyForCategory, parseLocaleQty, qtyErrorMessage } from '@/shared/lib/qty'

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
    // Stock is in the item's base UOM, but ordered/remaining/deliverNow are in the SO line's UOM —
    // uomFactor (base units per 1 of it, 1 when base) converts between them.
    availableStock: z.number(),
    uom: z.string().optional(),
    uomFactor: z.number().optional(),
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

    const factor = line.uomFactor ?? 1

    if (value * factor > line.availableStock) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: `Cannot exceed available stock (${Math.floor(line.availableStock / factor)}${line.uom ? ` ${line.uom}` : ''})`,
        path: ['deliverNow'],
      })
    }
  })

export const deliveryFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Location is required'),
  delivery_date: z.string().min(1, 'Delivery date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  terms_of_payment_id: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  fleet: z.string().optional().or(z.literal('')),
  driver: z.string().optional().or(z.literal('')),
  items: z.array(deliveryLineRowSchema).superRefine((items, ctx) => {
    const hasAny = items.some((line) => Number(line.deliverNow || 0) > 0)
    if (!hasAny) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Deliver at least one line item.' })
    }
  }),
})

/**
 * One row of a standalone/direct delivery (no source Sales Order) — user
 * picks the Item and types qty/rate directly, no Sales Order line to
 * snapshot or cap against. Mirrors directGoodsReceiptLineRowSchema.
 * `tax_id` is optional and manual only — a Direct Delivery line has no
 * Sales Order item to inherit tax from.
 */
export const directDeliveryLineRowSchema = z
  .object({
    item_id: z.string().min(1, 'Item is required'),
    item_code: z.string().optional().or(z.literal('')),
    item_name: z.string().optional().or(z.literal('')),
    item_uom: z.string().optional().or(z.literal('')),
    qtyCategory: z.enum(['unit', 'weight']),
    qty: z.string().min(1, 'Qty is required'),
    tax_id: z.string().optional().or(z.literal('')),
    rate: z
      .string()
      .min(1, 'Rate is required')
      .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  })
  .superRefine((line, ctx) => {
    const value = parseLocaleQty(line.qty)

    if (Number.isNaN(value) || value <= 0 || !isValidQtyForCategory(line.qty, line.qtyCategory)) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: qtyErrorMessage(line.qtyCategory), path: ['qty'] })
    }
  })

export const directDeliveryFormSchema = z.object({
  customer_id: z.string().min(1, 'Customer is required'),
  warehouse_id: z.string().min(1, 'Location is required'),
  delivery_date: z.string().min(1, 'Delivery date is required'),
  due_date: z.string().min(1, 'Due date is required'),
  terms_of_payment_id: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  fleet: z.string().optional().or(z.literal('')),
  driver: z.string().optional().or(z.literal('')),
  items: z.array(directDeliveryLineRowSchema).min(1, 'Add at least one line item.'),
})

export type DeliveryEditorValues = z.infer<typeof deliveryFormSchema>
export type DeliveryLineRow = z.infer<typeof deliveryLineRowSchema>
export type DirectDeliveryEditorValues = z.infer<typeof directDeliveryFormSchema>
export type DirectDeliveryLineRow = z.infer<typeof directDeliveryLineRowSchema>
